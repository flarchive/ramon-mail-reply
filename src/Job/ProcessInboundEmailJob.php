<?php

namespace Ramon\MailReply\Job;

use Flarum\Foundation\ErrorHandling\LogReporter;
use Flarum\Queue\AbstractJob;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Inbound\InboundProcessor;
use Throwable;

/**
 * Processa um payload de inbound fora da janela de resposta do webhook.
 *
 * Por que job e não inline:
 *   - Mailgun/Postmark dão budget de ~30s no webhook; se o processor
 *     demora (parsing MIME complexo, lookup de tag restritiva, transação
 *     concorrida), o provider reenfileira e o Flarum dedupa por
 *     Message-ID — mas pode acabar criando dois posts em corridas.
 *   - Pipe locais não têm timeout rígido, mas seguram um worker PHP-FPM
 *     com o cliente do MTA esperando o exit code.
 *
 * Em queue driver `sync` (default do Flarum), `dispatchSync` faz o
 * trabalho inline igual ao comportamento original — sem regressão. Quando
 * o operador configura Redis/database driver, dispatch normal já vira
 * background imediato.
 */
class ProcessInboundEmailJob extends AbstractJob
{
    public int $tries = 1;
    public int $timeout = 120;

    /**
     * @param array{to: string|array<int,string>, from?: string, subject?: string, body_text?: string, body_html?: string, headers?: array<string,string>} $payload
     */
    public function __construct(
        public array $payload,
    ) {
    }

    public function handle(
        InboundProcessor $processor,
        LogReporter $log,
        LoggerInterface $logger,
    ): void {
        try {
            $post = $processor->process($this->payload);

            $logger->info('[mail-reply] inbound processed by job', [
                'post_id' => (int) $post->id,
                'discussion_id' => (int) $post->discussion_id,
            ]);
        } catch (Throwable $e) {
            $logger->warning('[mail-reply] inbound rejected by job', [
                'reason' => $e->getMessage(),
                'class' => get_class($e),
                'recipients' => is_array($this->payload['to'] ?? null) ? count($this->payload['to']) : 0,
            ]);
            $log->report($e);
        }
    }
}
