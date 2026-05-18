<?php

namespace Ramon\MailReply\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MailReply\Mail\ReplyAddressConfig;
use Ramon\MailReply\Service\SecretBox;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

/**
 * Endpoint admin para validar a configuração IMAP antes do admin sair da
 * tela. Espelha o "Send test mail" do core (admin-only, retorna estrutura
 * JSON simples). Aceita override no body para que o admin possa testar
 * valores ainda não salvos.
 */
class TestImapController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ReplyAddressConfig $replyAddress,
        protected SecretBox $secretBox,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        if (! class_exists(ClientManager::class)) {
            return new JsonResponse([
                'ok' => false,
                'code' => 'missing_dependency',
                'message' => 'webklex/php-imap is not installed. Run: composer require webklex/php-imap',
            ], 200);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $config = $this->resolveConfig($body);

        foreach (['host', 'username', 'password'] as $required) {
            if (($config[$required] ?? '') === '') {
                return new JsonResponse([
                    'ok' => false,
                    'code' => 'missing_field',
                    'field' => $required,
                    'message' => sprintf('Missing required IMAP field "%s".', $required),
                ], 200);
            }
        }

        try {
            $clientManager = new ClientManager();
            $client = $clientManager->make([
                'host'           => $config['host'],
                'port'           => $config['port'],
                'encryption'     => $config['encryption'] === 'none' ? false : $config['encryption'],
                'validate_cert'  => $config['validate_cert'],
                'username'       => $config['username'],
                'password'       => $config['password'],
                'protocol'       => 'imap',
                'authentication' => null,
            ]);

            $client->connect();

            try {
                $folder = $client->getFolder($config['mailbox']);

                if ($folder === null) {
                    return new JsonResponse([
                        'ok' => false,
                        'code' => 'mailbox_not_found',
                        'message' => sprintf('Mailbox "%s" was not found on the server.', $config['mailbox']),
                    ], 200);
                }

                $unseen = $folder->query()->unseen()->limit(1)->get()->count();

                return new JsonResponse([
                    'ok' => true,
                    'mailbox' => $config['mailbox'],
                    'unseen' => $unseen,
                    'username' => $config['username'],
                    'expected_capture_pattern' => $this->capturePattern(),
                ]);
            } finally {
                try {
                    $client->disconnect();
                } catch (Throwable $e) {
                }
            }
        } catch (Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'code' => 'connection_failed',
                'message' => $this->sanitiseError($e, $config),
            ], 200);
        }
    }

    /**
     * Sanitiza a mensagem antes de devolver: credenciais (host:porta,
     * user:pass) às vezes aparecem em erros do webklex/php-imap. Expomos
     * só o tipo de erro + 200 chars, sem URL nem login.
     *
     * @param array{username: string, password: string, host: string} $config
     */
    protected function sanitiseError(Throwable $e, array $config): string
    {
        $message = (string) $e->getMessage();

        $message = str_replace(
            [$config['password'], $config['username'], $config['host']],
            ['[redacted]', '[user]', '[host]'],
            $message,
        );

        if ($config['password'] !== '') {
            $message = preg_replace('/'.preg_quote($config['password'], '/').'/', '[redacted]', $message);
        }

        return mb_substr($message, 0, 200);
    }

    /**
     * Quando o admin testa antes de salvar, a senha vem no body em
     * plaintext (override). Quando lê do settings (sem override), está
     * cifrada e precisa de decode via SecretBox.
     *
     * @param array<string, mixed> $override
     * @return array{host: string, port: int, encryption: string, validate_cert: bool, username: string, password: string, mailbox: string}
     */
    protected function resolveConfig(array $override): array
    {
        $pick = function (string $key, mixed $default) use ($override) {
            $value = $override[$key] ?? $this->settings->get('ramon-mail-reply.'.$key);

            return $value === null || $value === '' ? $default : $value;
        };

        $rawPassword = (string) $pick('imap_password', '');
        $password = '';
        if ($rawPassword !== '') {
            $fromOverride = array_key_exists('imap_password', $override) && $override['imap_password'] !== null && $override['imap_password'] !== '';
            try {
                $password = $fromOverride ? $rawPassword : $this->secretBox->decode($rawPassword);
            } catch (Throwable $e) {
                $password = '';
            }
        }

        return [
            'host'          => (string) $pick('imap_host', ''),
            'port'          => (int) $pick('imap_port', 993),
            'encryption'    => (string) $pick('imap_encryption', 'ssl'),
            'validate_cert' => (bool) $pick('imap_validate_cert', true),
            'username'      => (string) $pick('imap_username', ''),
            'password'      => $password,
            'mailbox'       => (string) $pick('imap_mailbox', 'INBOX'),
        ];
    }

    protected function capturePattern(): ?string
    {
        $parts = $this->replyAddress->parts();

        if ($parts === null) {
            return null;
        }

        return sprintf('%s+*@%s', $parts['local'], $parts['domain']);
    }
}
