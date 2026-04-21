<?php

namespace Ramon\MailReply\Api\Controller;

use Flarum\Foundation\ErrorHandling\LogReporter;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MailReply\Inbound\InboundProcessor;
use Throwable;

/**
 * Public webhook endpoint that inbound mail providers (Mailgun, Postmark,
 * SendGrid, CloudMailin, …) can hit. We normalise their payloads into a
 * common shape and hand off to InboundProcessor.
 *
 * Access control: we require a shared secret configured in the admin. Any
 * request without a valid `X-Mail-Reply-Token` header is rejected with 401.
 */
class InboundEmailController implements RequestHandlerInterface
{
    public function __construct(
        protected InboundProcessor $processor,
        protected SettingsRepositoryInterface $settings,
        protected LogReporter $log,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->authorize($request)) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        try {
            $payload = $this->normalise($request);
            $post = $this->processor->process($payload);
        } catch (Throwable $e) {
            $this->log->report($e);

            // 2xx keeps most providers from retrying a payload we cannot
            // recover from (invalid token, deleted discussion, etc).
            return new JsonResponse(['error' => $e->getMessage()], 200);
        }

        return new JsonResponse([
            'ok' => true,
            'post_id' => $post->id,
            'discussion_id' => $post->discussion_id,
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
     * @return array{to: array<int,string>, from: string, subject: string, body_text: string, body_html: string}
     */
    protected function normalise(ServerRequestInterface $request): array
    {
        $params = (array) $request->getParsedBody();

        // Fallback to JSON body when the provider sends application/json.
        if ($params === [] || $params === null) {
            $raw = (string) $request->getBody();
            $json = json_decode($raw, true);

            if (is_array($json)) {
                $params = $json;
            }
        }

        // Mailgun uses "recipient"/"sender"/"body-plain"/"body-html"; Postmark
        // uses "ToFull"/"From"/"TextBody"/"HtmlBody"; we accept both.
        $to = $params['recipient']
            ?? $params['To']
            ?? $params['to']
            ?? $this->mapAddressList($params['ToFull'] ?? []);

        $from = $params['sender']
            ?? $params['From']
            ?? $params['from']
            ?? '';

        return [
            'to' => is_array($to) ? $to : [(string) $to],
            'from' => (string) $from,
            'subject' => (string) ($params['subject'] ?? $params['Subject'] ?? ''),
            'body_text' => (string) ($params['body-plain'] ?? $params['TextBody'] ?? $params['text'] ?? ''),
            'body_html' => (string) ($params['body-html'] ?? $params['HtmlBody'] ?? $params['html'] ?? ''),
        ];
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
