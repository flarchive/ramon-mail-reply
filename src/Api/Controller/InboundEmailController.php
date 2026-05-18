<?php

namespace Ramon\MailReply\Api\Controller;

use Flarum\Foundation\ErrorHandling\LogReporter;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MailReply\Job\ProcessInboundEmailJob;
use Ramon\MailReply\Parser\Rfc822Parser;
use Throwable;

/**
 * Endpoint público de inbound. Recebe respostas por e-mail vindas de:
 *
 *   1. Scripts pipe locais no MTA — RFC 822 cru via `Content-Type:
 *      message/rfc822` (caminho recomendado quando o admin não pode/quer
 *      ter o Flarum lendo IMAP direto).
 *   2. Provedores como Mailgun/Postmark — form-data ou JSON canônico.
 *
 * Autenticação: header `X-Mail-Reply-Token` com segredo configurado no
 * admin. CSRF é isento via `Extend\Csrf::exemptRoute()` no extend.php
 * (caller é externo, não tem session/CSRF token).
 *
 * Modo de operação: o admin escolhe um modo único de entrega:
 * `imap` (poller dentro do queue:work), `webhook` (este endpoint),
 * `disabled` (nenhum). Quando o modo não é `webhook`, o endpoint retorna
 * 403 — evita dupla entrega caso o admin tenha configurado ambos.
 *
 * O processamento pesado (parsing MIME, lookup, validação, criação do
 * post) sai pra `ProcessInboundEmailJob` via Bus — o controller só
 * normaliza o payload e despacha. Em queue driver `sync` o comportamento
 * é igual ao inline antigo; em queue real, libera o budget do webhook do
 * provider (Mailgun/Postmark cortam em ~30s).
 */
class InboundEmailController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LogReporter $log,
        protected Rfc822Parser $rfc822,
        protected BusDispatcher $bus,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $mode = (string) $this->settings->get('ramon-mail-reply.delivery_mode');

        if ($mode !== 'webhook') {
            return new JsonResponse([
                'error' => 'webhook_not_active',
                'message' => "Webhook is disabled. Current delivery_mode='{$mode}'. Set to 'webhook' in Admin → Mail Reply.",
            ], 403);
        }

        if (! $this->authorize($request)) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        try {
            $payload = $this->normalise($request);
        } catch (Throwable $e) {
            $this->log->report($e);

            return new JsonResponse([
                'ok' => false,
                'reason' => 'normalise_failed: '.$e->getMessage(),
                'class' => get_class($e),
            ], 200);
        }

        try {
            $this->bus->dispatchSync(new ProcessInboundEmailJob($payload));
        } catch (Throwable $e) {
            $this->log->report($e);

            return new JsonResponse([
                'ok' => false,
                'reason' => $e->getMessage(),
                'class' => get_class($e),
            ], 200);
        }

        return new JsonResponse([
            'ok' => true,
            'received' => true,
        ]);
    }

    protected function authorize(ServerRequestInterface $request): bool
    {
        $expected = (string) $this->settings->get('ramon-mail-reply.webhook_secret');

        if ($expected === '') {
            return false;
        }

        $provided = $request->getHeaderLine('X-Mail-Reply-Token');

        return hash_equals($expected, $provided);
    }

    /**
     * Aceita três formas de payload:
     *
     *   1. `Content-Type: message/rfc822` (ou body com cara de header)
     *      — RFC 822 cru. Caminho preferido pra scripts pipe locais.
     *   2. `application/json` canônico com {to, from, subject,
     *      body_text, body_html}.
     *   3. form-data/x-www-form-urlencoded com nomes dos provedores
     *      (Mailgun: recipient/sender/body-plain; Postmark: ToFull/
     *      From/TextBody).
     *
     * @return array{to: array<int,string>, from: string, subject: string, body_text: string, body_html: string}
     */
    protected function normalise(ServerRequestInterface $request): array
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        $raw = (string) $request->getBody();

        if (str_contains($contentType, 'message/rfc822') || $this->looksLikeRfc822($raw, $contentType)) {
            $parsed = $this->rfc822->parse($raw);

            return [
                'to'        => $parsed['to'],
                'from'      => $parsed['from'],
                'subject'   => $parsed['subject'],
                'body_text' => $parsed['body_text'],
                'body_html' => $parsed['body_html'],
            ];
        }

        $params = (array) $request->getParsedBody();

        if ($params === [] || $params === null) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $params = $json;
            }
        }

        $to = $params['recipient']
            ?? $params['To']
            ?? $params['to']
            ?? $this->mapAddressList($params['ToFull'] ?? []);

        $from = $params['sender']
            ?? $params['From']
            ?? $params['from']
            ?? '';

        return [
            'to'        => is_array($to) ? $to : [(string) $to],
            'from'      => (string) $from,
            'subject'   => (string) ($params['subject'] ?? $params['Subject'] ?? ''),
            'body_text' => (string) ($params['body-plain'] ?? $params['TextBody'] ?? $params['body_text'] ?? $params['text'] ?? ''),
            'body_html' => (string) ($params['body-html'] ?? $params['HtmlBody'] ?? $params['body_html'] ?? $params['html'] ?? ''),
        ];
    }

    /**
     * Heurística para detectar RFC 822 cru sem Content-Type adequado.
     */
    protected function looksLikeRfc822(string $raw, string $contentType): bool
    {
        if (str_contains($contentType, 'application/json')) {
            return false;
        }

        $head = ltrim(substr($raw, 0, 2048));

        if ($head === '' || $head[0] === '{' || $head[0] === '[' || $head[0] === '<') {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9\-]*:\s/', $head);
    }

    /**
     * @param array<int, array{Email?: string}> $list
     * @return array<int, string>
     */
    protected function mapAddressList(array $list): array
    {
        $out = [];

        foreach ($list as $entry) {
            if (is_array($entry) && isset($entry['Email'])) {
                $out[] = (string) $entry['Email'];
            } elseif (is_string($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
