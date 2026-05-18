<?php

namespace Ramon\MailReply\Token;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Mail\ReplyAddressConfig;
use Ramon\MailReply\Service\SecretBox;
use Throwable;

/**
 * Token stateless: o alias do e-mail é puramente determinístico em
 * (user_id, discussion_id [, post_id]), assinado com HMAC. Não há tabela
 * de tokens nem estado por notificação — o identificador único do e-mail
 * é o próprio par/trio codificado no local part, validado por hash_equals
 * no inbound.
 *
 * Wire format do local part (post_id opcional):
 *
 *     {localPart}+u{userId}d{discussionId}-{sig}             (sem post)
 *     {localPart}+u{userId}d{discussionId}p{postId}-{sig}    (com post)
 *
 * Exemplos:
 *     reply+u42d123-a1b2c3d4e5f60718@reply.forum.com
 *     reply+u42d123p987-9f0a1b2c3d4e5f6a@reply.forum.com
 *
 * O postId, quando presente, é o post que originou a notificação (post
 * que mencionou o usuário, post a que ele está inscrito etc.). Permite
 * que o InboundProcessor prepende `@"author"#p{postId}` no início da
 * resposta — replicando o comportamento de "Responder" do fórum.
 *
 * Reusabilidade: o alias é estável por (user, discussion[, post]) — o
 * mesmo e-mail de notificação pode ser respondido N vezes; cada resposta
 * vira um post.
 */
class TokenService
{
    private const SIG_LENGTH = 16;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ReplyAddressConfig $replyAddress,
        protected Repository $cache,
        protected SecretBox $secretBox,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Monta o endereço para o trio (usuário, tópico, post). post_id é
     * opcional — quando informado, o alias inclui `p{postId}` e a sig
     * cobre o trio inteiro.
     */
    public function mintAddress(int $userId, int $discussionId, ?int $postId = null): ?string
    {
        $parts = $this->replyAddress->parts();

        if ($parts === null) {
            return null;
        }

        $tag = $this->tag($userId, $discussionId, $postId);

        return sprintf('%s+%s@%s', $parts['local'], $tag, $parts['domain']);
    }

    /**
     * Tag determinística — local part após o `+`.
     */
    public function tag(int $userId, int $discussionId, ?int $postId = null): string
    {
        $payload = $this->payload($userId, $discussionId, $postId);
        $sig = substr($this->sign($payload), 0, self::SIG_LENGTH);

        if ($postId !== null && $postId > 0) {
            return sprintf('u%dd%dp%d-%s', $userId, $discussionId, $postId, $sig);
        }

        return sprintf('u%dd%d-%s', $userId, $discussionId, $sig);
    }

    /**
     * Faz parse de um endereço de inbound. Aceita as duas variantes
     * (com ou sem `p{postId}`). Retorna null para tudo o que for
     * inválido (prefixo errado, assinatura forjada, formato corrompido).
     *
     * @return array{user_id: int, discussion_id: int, post_id: ?int}|null
     */
    public function parseAddress(string $address): ?array
    {
        if (preg_match('/<([^>]+)>/', $address, $m)) {
            $address = $m[1];
        }

        if (! preg_match('/^([^@]+)@/', $address, $m)) {
            return null;
        }

        $local = $m[1];

        if (! str_contains($local, '+')) {
            return null;
        }

        [$prefix, $rest] = explode('+', $local, 2);

        $expectedLocal = $this->replyAddress->parts()['local'] ?? null;

        if ($expectedLocal === null || ! hash_equals($expectedLocal, $prefix)) {
            return null;
        }

        if (preg_match('/^u(\d+)d(\d+)p(\d+)-([a-f0-9]{'.self::SIG_LENGTH.'})$/i', $rest, $m)) {
            return $this->verify(
                (int) $m[1],
                (int) $m[2],
                (int) $m[3],
                strtolower($m[4]),
            );
        }

        if (preg_match('/^u(\d+)d(\d+)-([a-f0-9]{'.self::SIG_LENGTH.'})$/i', $rest, $m)) {
            return $this->verify(
                (int) $m[1],
                (int) $m[2],
                null,
                strtolower($m[3]),
            );
        }

        return null;
    }

    /**
     * @return array{user_id: int, discussion_id: int, post_id: ?int}|null
     */
    protected function verify(int $userId, int $discussionId, ?int $postId, string $providedSig): ?array
    {
        $expectedSig = substr($this->sign($this->payload($userId, $discussionId, $postId)), 0, self::SIG_LENGTH);

        if (! hash_equals($expectedSig, $providedSig)) {
            return null;
        }

        return [
            'user_id' => $userId,
            'discussion_id' => $discussionId,
            'post_id' => $postId,
        ];
    }

    protected function payload(int $userId, int $discussionId, ?int $postId = null): string
    {
        if ($postId !== null && $postId > 0) {
            return 'u'.$userId.'d'.$discussionId.'p'.$postId;
        }

        return 'u'.$userId.'d'.$discussionId;
    }

    /**
     * Assina o payload usando uma chave HMAC. Se o admin não configurou um
     * segredo, geramos uma chave aleatória de 32 bytes na primeira chamada
     * e persistimos em settings — mantém o binding estável entre processos
     * e workers de fila sem depender de APP_KEY/.env.
     */
    protected function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->resolveKey());
    }

    /**
     * Resolve a chave HMAC. Sempre persistida cifrada via SecretBox em
     * envelope AEAD. Aceita plaintext legado na leitura — quando
     * encontrado, re-escreve cifrado para zerar o débito sem perder o
     * binding dos aliases já emitidos.
     *
     * Geração inicial fica numa região crítica protegida por cache lock
     * atômico de 5s; sem isso dois workers chegando ao mesmo tempo
     * gerariam chaves diferentes e o segundo sobrescreveria a do primeiro,
     * invalidando aliases já enviados. Dentro do lock fazemos double-check
     * do setting.
     */
    protected function resolveKey(): string
    {
        $stored = (string) $this->settings->get('ramon-mail-reply.secret');

        if ($stored !== '') {
            return $this->decryptOrMigrate($stored, 'secret');
        }

        $lock = $this->cache->lock('mail-reply.secret_gen', 5);

        try {
            $lock->block(5);

            $stored = (string) $this->settings->get('ramon-mail-reply.secret');

            if ($stored !== '') {
                return $this->decryptOrMigrate($stored, 'secret');
            }

            $generated = bin2hex(random_bytes(32));
            $this->settings->set('ramon-mail-reply.secret', $this->secretBox->encode($generated));

            return $generated;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * Decifra ou trata como plaintext legado. Quando legado, re-escreve
     * cifrado lazily — o operador não precisa rodar comando de migração.
     */
    protected function decryptOrMigrate(string $stored, string $settingSuffix): string
    {
        try {
            $plain = $this->secretBox->decode($stored);
        } catch (Throwable $e) {
            $this->logger->error('[mail-reply] failed to decrypt secret', [
                'setting' => 'ramon-mail-reply.'.$settingSuffix,
                'reason' => $e->getMessage(),
            ]);
            throw $e;
        }

        if (! SecretBox::isEncrypted($stored)) {
            try {
                $this->settings->set('ramon-mail-reply.'.$settingSuffix, $this->secretBox->encode($plain));
                $this->logger->info('[mail-reply] migrated plaintext secret to encrypted form', [
                    'setting' => 'ramon-mail-reply.'.$settingSuffix,
                ]);
            } catch (Throwable $e) {
                $this->logger->warning('[mail-reply] lazy re-encrypt failed; continuing with plaintext', [
                    'setting' => 'ramon-mail-reply.'.$settingSuffix,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return $plain;
    }
}
