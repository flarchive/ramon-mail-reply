<?php

namespace Ramon\MailReply;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Mail\MutateEmail;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Job\FetchInboundEmailsJob;
use Ramon\MailReply\Mail\CombinedEmailMutator;
use Throwable;

/**
 * Reescreve o binding de MutateEmail → CombinedEmailMutator (workaround do
 * events->until() do Laravel que para no primeiro listener), faz o
 * bootstrap automático do fetch loop, e garante que o `webhook_secret`
 * existe sempre que o modo está em `webhook`.
 *
 * Bootstrap: se IMAP está configurado e não há FetchInboundEmailsJob ativo,
 * dispatcha um. A partir daí o job se re-enfileira a cada 60s — tudo dentro
 * do queue:work normal do Flarum, sem cron nem comando manual. Em caso de
 * morte do loop (queue:flush, worker crash), o próximo HTTP request ou tick
 * do worker redispatcha automaticamente via heartbeat com TTL 180s.
 */
class MailReplyServiceProvider extends AbstractServiceProvider
{
    private const HEARTBEAT_KEY = 'mail-reply.loop_heartbeat';
    private const HEARTBEAT_TTL = 180;
    private const BOOTSTRAP_LOCK_KEY = 'mail-reply.bootstrap_lock';
    private const WEBHOOK_SECRET_LOCK_KEY = 'mail-reply.webhook_secret_gen';

    public function register(): void
    {
        $this->container->bind(MutateEmail::class, CombinedEmailMutator::class);
    }

    public function boot(): void
    {
        $this->ensureWebhookSecret();
        $this->ensureFetchLoopRunning();
    }

    /**
     * Se o admin ativa modo `webhook` mas esqueceu de setar
     * `webhook_secret`, o endpoint rejeita silenciosamente com 401 (o
     * authorize devolve false quando expected é vazio). Em vez de deixar
     * o admin descobrir só depois de configurar o MTA, geramos um segredo
     * aleatório aqui e logamos warn — admin abre a aba "Webhook" e vê o
     * segredo já preenchido pronto pra copiar no script PHP.
     *
     * Race entre múltiplos workers chegando junto: cache lock atômico de
     * 5s + double-check do setting dentro do lock.
     */
    protected function ensureWebhookSecret(): void
    {
        try {
            /** @var SettingsRepositoryInterface $settings */
            $settings = $this->container->make(SettingsRepositoryInterface::class);

            if ((string) $settings->get('ramon-mail-reply.delivery_mode') !== 'webhook') {
                return;
            }

            if ((string) $settings->get('ramon-mail-reply.webhook_secret') !== '') {
                return;
            }

            /** @var Repository $cache */
            $cache = $this->container->make(Repository::class);

            $lock = $cache->lock(self::WEBHOOK_SECRET_LOCK_KEY, 5);
            if (! $lock->get()) {
                return;
            }

            try {
                if ((string) $settings->get('ramon-mail-reply.webhook_secret') !== '') {
                    return;
                }

                $generated = bin2hex(random_bytes(32));
                $settings->set('ramon-mail-reply.webhook_secret', $generated);

                /** @var LoggerInterface $logger */
                $logger = $this->container->make(LoggerInterface::class);
                $logger->warning('[mail-reply] webhook_secret was missing; auto-generated one — copy it from Admin → Mail Reply → Webhook into the MTA script.');
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            try {
                $this->container->make(LoggerInterface::class)
                    ->warning('[mail-reply] webhook_secret bootstrap failed', [
                        'error' => $e->getMessage(),
                    ]);
            } catch (Throwable $ignored) {
            }
        }
    }

    /**
     * Loop só faz sentido em modo IMAP com host/user/senha preenchidos.
     * Race entre múltiplos requests/workers (todos vendo o heartbeat
     * expirado) é resolvida pelo cache lock atômico: apenas UM dispatcha;
     * os outros saem. Double-check dentro do lock cobre o caso de outro
     * processo ter setado o flag enquanto bloqueávamos.
     */
    protected function ensureFetchLoopRunning(): void
    {
        try {
            /** @var SettingsRepositoryInterface $settings */
            $settings = $this->container->make(SettingsRepositoryInterface::class);

            $mode = (string) $settings->get('ramon-mail-reply.delivery_mode');
            if ($mode !== 'imap') {
                return;
            }

            foreach (['imap_host', 'imap_username', 'imap_password'] as $key) {
                if (trim((string) $settings->get('ramon-mail-reply.'.$key)) === '') {
                    return;
                }
            }

            /** @var Repository $cache */
            $cache = $this->container->make(Repository::class);

            if ($cache->has(self::HEARTBEAT_KEY)) {
                return;
            }

            $lock = $cache->lock(self::BOOTSTRAP_LOCK_KEY, 30);

            if (! $lock->get()) {
                return;
            }

            try {
                if ($cache->has(self::HEARTBEAT_KEY)) {
                    return;
                }

                $cache->put(self::HEARTBEAT_KEY, time(), self::HEARTBEAT_TTL);

                /** @var Dispatcher $bus */
                $bus = $this->container->make(Dispatcher::class);
                $bus->dispatch(new FetchInboundEmailsJob());
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            try {
                $this->container->make(LoggerInterface::class)
                    ->warning('[mail-reply] auto-bootstrap failed', [
                        'error' => $e->getMessage(),
                    ]);
            } catch (Throwable $ignored) {
            }
        }
    }
}
