<?php

namespace Ramon\MailReply\Service;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use RuntimeException;

/**
 * Envelope-encryption para segredos persistidos na tabela `settings`:
 * chave HMAC do mail-reply e senha IMAP. Usa AEAD XChaCha20-Poly1305 com
 * chave de 32 bytes derivada via SHA-256 de `config.url` + `paths.base`
 * — par estável por instalação, sem dependência de .env.
 *
 * Formato do ciphertext no banco:
 *
 *     mp1:base64url(nonce(24) || ciphertext+tag)
 *
 * O prefixo `mp1:` (mail-reply protect v1) sinaliza o formato e permite:
 *
 *   - Detectar valores ainda em plaintext (legado) — `decode()` devolve
 *     a string crua para que o reader trate como migração lazy.
 *   - Migrar para `mp2:` no futuro sem quebrar valores existentes.
 *
 * Refuse-to-persist: `encode()` lança `RuntimeException` se o host não
 * tem `sodium_crypto_aead_xchacha20poly1305_ietf_*`. Plaintext fallback é
 * exatamente o bug que esta classe evita — preferimos quebrar o admin
 * (com mensagem clara) a degradar silenciosamente.
 */
class SecretBox
{
    private const PREFIX = 'mp1:';

    public function __construct(
        protected Config $config,
        protected Paths $paths,
    ) {
    }

    /**
     * Indica se o host suporta a primitiva. Útil para o admin checar antes
     * de ativar features que dependem de persistir segredos cifrados.
     */
    public static function isSupported(): bool
    {
        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            && defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES');
    }

    /**
     * Cifra `$plaintext`. Retorna sempre uma string prefixada com `mp1:`.
     */
    public function encode(string $plaintext): string
    {
        if (! self::isSupported()) {
            throw new RuntimeException(
                'libsodium AEAD XChaCha20-Poly1305 is required to persist mail-reply secrets. '
                .'Install sodium extension or upgrade PHP to a build that ships it.'
            );
        }

        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            '',
            $nonce,
            $key,
        );

        return self::PREFIX.$this->b64UrlEncode($nonce.$ciphertext);
    }

    /**
     * Decifra um valor. Aceita três entradas:
     *
     *   - String com prefixo `mp1:` → decifra normalmente.
     *   - String sem prefixo → trata como plaintext legado e devolve
     *     intacta (caller é responsável por re-escrever cifrada).
     *   - String vazia → devolve vazia.
     *
     * Lança `RuntimeException` se a primitiva não está disponível, o
     * payload está truncado ou a verificação AEAD falhou (chave errada,
     * adulteração, payload de outra instalação).
     */
    public function decode(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (! str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        if (! self::isSupported()) {
            throw new RuntimeException(
                'Encrypted setting found but libsodium AEAD is not available on this host.'
            );
        }

        $payload = $this->b64UrlDecode(substr($value, strlen(self::PREFIX)));
        $nlen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if (strlen($payload) < $nlen + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new RuntimeException('Encrypted mail-reply secret is truncated.');
        }

        $nonce = substr($payload, 0, $nlen);
        $ciphertext = substr($payload, $nlen);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            '',
            $nonce,
            $this->key(),
        );

        if ($plaintext === false) {
            throw new RuntimeException(
                'Failed to decrypt mail-reply secret — wrong derived key or tampered payload.'
            );
        }

        return $plaintext;
    }

    /**
     * `true` se o valor já está cifrado neste formato.
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Chave AEAD de 32 bytes derivada de inputs estáveis da instalação.
     * Mesma chave em CLI e HTTP; muda apenas se o operador alterar
     * `url`/`paths.base` no config.php — caso em que as notificações
     * antigas também precisam ser regeradas.
     */
    protected function key(): string
    {
        $url = (string) ($this->config['url'] ?? '');
        $base = (string) $this->paths->base;

        if ($url === '' || $base === '') {
            throw new RuntimeException(
                'mail-reply SecretBox needs `url` and `paths.base` in config.php to derive its envelope key.'
            );
        }

        return hash('sha256', 'ramon-mail-reply|v1|'.$url.'|'.$base, true);
    }

    protected function b64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    protected function b64UrlDecode(string $encoded): string
    {
        $pad = strlen($encoded) % 4;
        if ($pad) {
            $encoded .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
