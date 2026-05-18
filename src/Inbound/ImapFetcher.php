<?php

namespace Ramon\MailReply\Inbound;

use Flarum\Foundation\ErrorHandling\LogReporter;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Service\SecretBox;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

/**
 * Coleta réplicas de uma caixa única (ex.: reply@meufórum.com) via IMAP,
 * convertendo cada mensagem em um payload no mesmo formato do webhook e
 * delegando ao InboundProcessor. Útil quando o operador não pode usar um
 * provedor de inbound (Mailgun/Postmark/…) — basta criar uma conta de
 * resposta com captura "+TOKEN" e configurar host/usuário/senha aqui.
 *
 * O acoplamento com webklex/php-imap é opcional: a dependência é declarada
 * como `suggest` no composer.json; em tempo de execução verificamos
 * class_exists para emitir um erro acionável caso não esteja instalada.
 */
class ImapFetcher
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected InboundProcessor $processor,
        protected LogReporter $log,
        protected LoggerInterface $logger,
        protected SecretBox $secretBox,
    ) {
    }

    /**
     * Mensagens com erro de parsing ficam marcadas como Seen mas NÃO são
     * deletadas, para o operador poder inspecionar manualmente. Mensagens
     * processadas (ou irrecuperavelmente rejeitadas pelo processor) são
     * deletadas ou marcadas como Seen conforme `imap_delete_after`.
     *
     * @return array{processed: int, failed: int, skipped: int}
     */
    public function fetch(int $limit = 50): array
    {
        $this->ensureLibraryAvailable();

        $config = $this->config();
        $this->assertConfigured($config);

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
                throw new RuntimeException(sprintf('IMAP mailbox "%s" not found.', $config['mailbox']));
            }

            $messages = $folder->query()
                ->unseen()
                ->setFetchOrder('asc')
                ->limit($limit)
                ->get();

            $candidateCount = is_object($messages) && method_exists($messages, 'count') ? $messages->count() : count($messages);

            $this->logger->info('[mail-reply] IMAP query returned messages', [
                'mailbox' => $config['mailbox'],
                'unseen_candidates' => $candidateCount,
            ]);

            $processed = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($messages as $message) {
                $payload = null;
                $error = null;

                try {
                    $payload = $this->buildPayload($message);
                } catch (Throwable $e) {
                    $error = $e;
                    $this->log->report($e);
                }

                if ($error !== null) {
                    $failed++;
                    try {
                        $message->setFlag('Seen');
                    } catch (Throwable $e) {
                    }
                    continue;
                }

                if ($payload === null) {
                    $skipped++;
                    $this->logger->info('[mail-reply] message skipped (no recipients extracted)', [
                        'message_id' => method_exists($message, 'getMessageId') ? (string) $message->getMessageId() : null,
                    ]);
                    try {
                        $message->setFlag('Seen');
                    } catch (Throwable $e) {
                    }
                    continue;
                }

                try {
                    $this->processor->process($payload);
                    $processed++;
                    $this->logger->info('[mail-reply] message processed into a post', [
                        'message_id' => method_exists($message, 'getMessageId') ? (string) $message->getMessageId() : null,
                        'recipients' => count($payload['to']),
                    ]);
                } catch (Throwable $e) {
                    $failed++;
                    $this->logger->warning('[mail-reply] InboundProcessor rejected payload', [
                        'message_id' => method_exists($message, 'getMessageId') ? (string) $message->getMessageId() : null,
                        'recipients' => count($payload['to']),
                        'reason' => $e->getMessage(),
                    ]);
                    $this->log->report($e);
                }

                try {
                    if ($config['delete_after']) {
                        $message->delete();
                    } else {
                        $message->setFlag('Seen');
                    }
                } catch (Throwable $e) {
                }
            }

            $client->expunge();

            $this->logger->info('[mail-reply] IMAP fetch cycle complete', [
                'mailbox' => $config['mailbox'],
                'processed' => $processed,
                'failed' => $failed,
                'skipped' => $skipped,
            ]);

            return ['processed' => $processed, 'failed' => $failed, 'skipped' => $skipped];
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * Senha IMAP é persistida cifrada via SecretBox AEAD. Tolerantes a
     * valores legados em plaintext: decifragem retorna a string crua
     * quando o prefixo `mp1:` não está presente — caller usa o valor
     * normalmente e re-encripta lazy.
     *
     * @return array{host: string, port: int, encryption: string, validate_cert: bool, username: string, password: string, mailbox: string, delete_after: bool}
     */
    protected function config(): array
    {
        $rawPassword = (string) $this->settings->get('ramon-mail-reply.imap_password');
        $password = '';
        if ($rawPassword !== '') {
            $password = $this->secretBox->decode($rawPassword);
            if (! SecretBox::isEncrypted($rawPassword)) {
                try {
                    $this->settings->set('ramon-mail-reply.imap_password', $this->secretBox->encode($password));
                    $this->logger->info('[mail-reply] migrated plaintext imap_password to encrypted form');
                } catch (Throwable $e) {
                    $this->logger->warning('[mail-reply] lazy re-encrypt of imap_password failed', [
                        'reason' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'host'          => trim((string) $this->settings->get('ramon-mail-reply.imap_host')),
            'port'          => (int) ($this->settings->get('ramon-mail-reply.imap_port') ?? 993),
            'encryption'    => (string) ($this->settings->get('ramon-mail-reply.imap_encryption') ?? 'ssl'),
            'validate_cert' => (bool) ($this->settings->get('ramon-mail-reply.imap_validate_cert') ?? true),
            'username'      => (string) $this->settings->get('ramon-mail-reply.imap_username'),
            'password'      => $password,
            'mailbox'       => (string) ($this->settings->get('ramon-mail-reply.imap_mailbox') ?? 'INBOX'),
            'delete_after'  => (bool) ($this->settings->get('ramon-mail-reply.imap_delete_after') ?? true),
        ];
    }

    /**
     * @param array{host: string, username: string, password: string} $config
     */
    protected function assertConfigured(array $config): void
    {
        foreach (['host', 'username', 'password'] as $key) {
            if ($config[$key] === '') {
                throw new RuntimeException(sprintf('Mail-reply IMAP not configured: missing "%s".', $key));
            }
        }
    }

    protected function ensureLibraryAvailable(): void
    {
        if (! class_exists(ClientManager::class)) {
            throw new RuntimeException(
                'webklex/php-imap is required for IMAP polling. Run: composer require webklex/php-imap',
            );
        }
    }

    /**
     * Headers de entrega são consultados primeiro porque preservam o
     * destino real (`reply+TOKEN@`) mesmo quando o MTA reescreve o `To:`
     * visível. `To:` e `Cc:` ficam como fallback.
     *
     * @return array{to: array<int,string>, from: string, subject: string, body_text: string, body_html: string}|null
     */
    protected function buildPayload(object $message): ?array
    {
        $to = [];

        foreach (['Envelope-To', 'X-Original-To', 'Delivered-To', 'X-Envelope-To', 'X-Delivered-To'] as $headerName) {
            foreach ($this->readHeader($message, $headerName) as $value) {
                $email = $this->extractEmail($value);
                if ($email !== '') {
                    $to[] = $email;
                }
            }
        }

        foreach ((array) $message->getTo() as $address) {
            $mail = $this->addressEmail($address);
            if ($mail !== '') {
                $to[] = $mail;
            }
        }

        foreach ((array) $message->getCc() as $address) {
            $mail = $this->addressEmail($address);
            if ($mail !== '') {
                $to[] = $mail;
            }
        }

        $to = array_values(array_unique($to));

        if ($to === []) {
            return null;
        }

        $from = '';

        foreach ((array) $message->getFrom() as $address) {
            $from = $this->addressEmail($address);
            break;
        }

        return [
            'to'        => $to,
            'from'      => $from,
            'subject'   => (string) $message->getSubject(),
            'body_text' => (string) $message->getTextBody(),
            'body_html' => (string) $message->getHTMLBody(),
        ];
    }

    /**
     * Extrai o endereço de e-mail de qualquer representação que o
     * webklex/php-imap possa entregar (varia por versão e por header):
     *
     *   - `Webklex\PHPIMAP\Address` (objeto, ->mail / ->personal / ->host)
     *   - `stdClass` com `->mail` ou `->email`
     *   - array `['mail' => '...']` ou `['email' => '...']`
     *   - array indexado `[0 => 'user', 1 => 'domain.com']`
     *   - string `"Foo Bar <foo@bar>"` ou `"foo@bar"`
     *
     * Devolve '' silenciosamente se não conseguirmos extrair — o loop
     * continua processando os outros endereços.
     */
    protected function addressEmail(mixed $address): string
    {
        if (is_string($address)) {
            return $this->extractEmail($address);
        }

        if (is_object($address)) {
            foreach (['mail', 'email', 'address'] as $prop) {
                if (isset($address->$prop)) {
                    return (string) $address->$prop;
                }
            }
            if (method_exists($address, '__toString')) {
                return $this->extractEmail((string) $address);
            }
            return '';
        }

        if (is_array($address)) {
            foreach (['mail', 'email', 'address'] as $key) {
                if (isset($address[$key]) && is_string($address[$key])) {
                    return $address[$key];
                }
            }
            if (isset($address[0], $address[1]) && is_string($address[0]) && is_string($address[1])) {
                return $address[0].'@'.$address[1];
            }
        }

        return '';
    }

    /**
     * Lê todos os valores de um header arbitrário da mensagem. A API do
     * webklex varia bastante entre versões — alguns retornam objeto Header,
     * outros valor direto, outros uma collection. Tentamos os caminhos mais
     * comuns e degradamos para vazio sem explodir.
     *
     * @return array<int, string>
     */
    protected function readHeader(object $message, string $headerName): array
    {
        $values = [];

        try {
            if (method_exists($message, 'getHeader')) {
                $header = $message->getHeader();

                if (is_object($header) && method_exists($header, 'get')) {
                    $candidate = $header->get($headerName);
                    foreach ($this->normalizeHeaderValue($candidate) as $v) {
                        $values[] = $v;
                    }
                }
            }

            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $headerName))));
            if (property_exists($message, $camel)) {
                foreach ($this->normalizeHeaderValue($message->$camel) as $v) {
                    $values[] = $v;
                }
            }

            if (method_exists($message, 'get')) {
                $candidate = $message->get($headerName);
                foreach ($this->normalizeHeaderValue($candidate) as $v) {
                    $values[] = $v;
                }
            }
        } catch (Throwable $e) {
        }

        return $values;
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    protected function normalizeHeaderValue(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            return [$raw];
        }

        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $item) {
                foreach ($this->normalizeHeaderValue($item) as $v) {
                    $out[] = $v;
                }
            }
            return $out;
        }

        if (is_object($raw)) {
            if (method_exists($raw, 'toString')) {
                $str = (string) $raw->toString();
                return $str === '' ? [] : [$str];
            }
            if (method_exists($raw, '__toString')) {
                $str = (string) $raw;
                return $str === '' ? [] : [$str];
            }
        }

        return [];
    }

    /**
     * Extrai o endereço de e-mail de uma string que pode ser
     * `"Foo Bar <foo@bar>"`, `<foo@bar>` ou `foo@bar`.
     */
    protected function extractEmail(string $raw): string
    {
        if (preg_match('/<([^>]+@[^>]+)>/', $raw, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $raw, $m)) {
            return trim($m[0]);
        }

        return '';
    }
}
