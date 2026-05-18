<?php

namespace Ramon\MailReply\Job;

use Carbon\Carbon;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Inbound\ImapFetcher;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

/**
 * Job auto-re-enfileirável que faz o ciclo do IMAP fetch dentro do
 * `queue:work` (sem precisar de cron, sem comando manual de bootstrap,
 * sem daemon --loop separado).
 *
 * Fluxo (depois do primeiro dispatch automático pelo ServiceProvider):
 *   1. queue:work pega o job
 *   2. handle() refresca o heartbeat cache flag (TTL 180s)
 *   3. roda 1 fetch (se IMAP configurado)
 *   4. dispatch do PRÓXIMO job com delay = 60s
 *
 * Se o ciclo morre (queue:flush, worker crash sem retomar, etc.), o
 * heartbeat expira em 180s e o ServiceProvider re-bootstrappa no próximo
 * boot do app (HTTP request ou início do worker).
 */
class FetchInboundEmailsJob extends AbstractJob
{
    public const POLL_INTERVAL_SECONDS = 60;
    public const HEARTBEAT_KEY = 'mail-reply.loop_heartbeat';
    public const HEARTBEAT_TTL = 180;

    /**
     * Encerra o ciclo (não re-enfileira) e libera o heartbeat quando o modo
     * muda pra webhook/disabled enquanto o loop está ativo. Falhas individuais
     * de fetch não derrubam o loop — re-enfileira mesmo assim.
     */
    public function handle(
        SettingsRepositoryInterface $settings,
        ImapFetcher $fetcher,
        Dispatcher $bus,
        Repository $cache,
        LoggerInterface $logger,
    ): void {
        $mode = (string) $settings->get('ramon-mail-reply.delivery_mode');
        if ($mode !== 'imap') {
            $cache->forget(self::HEARTBEAT_KEY);
            $logger->info('[mail-reply] fetch loop stopping', [
                'reason' => 'delivery_mode_changed',
                'current_mode' => $mode,
            ]);
            return;
        }

        $cache->put(self::HEARTBEAT_KEY, time(), self::HEARTBEAT_TTL);

        if ($this->imapConfigured($settings) && class_exists(ClientManager::class)) {
            try {
                $fetcher->fetch(50);
            } catch (Throwable $e) {
                $logger->warning('[mail-reply] fetch loop iteration failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            $logger->debug('[mail-reply] fetch loop tick skipped — IMAP not configured');
        }

        $bus->dispatch(
            (new self())->delay(Carbon::now()->addSeconds(self::POLL_INTERVAL_SECONDS)),
        );
    }

    protected function imapConfigured(SettingsRepositoryInterface $settings): bool
    {
        foreach (['imap_host', 'imap_username', 'imap_password'] as $key) {
            if (trim((string) $settings->get('ramon-mail-reply.'.$key)) === '') {
                return false;
            }
        }

        return true;
    }
}
