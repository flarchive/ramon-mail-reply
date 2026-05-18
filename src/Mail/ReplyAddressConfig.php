<?php

namespace Ramon\MailReply\Mail;

use Flarum\Settings\SettingsRepositoryInterface;
use RuntimeException;

/**
 * Endereço dedicado de resposta — independente do mail_from do Flarum.
 *
 * Caso de uso: o admin envia notificações por um remetente normal
 * (ex.: noreply@meuforum.com) e mantém uma caixa separada para coletar as
 * respostas via IMAP (ex.: inbox@reply.meuforum.com). Essa caixa pode estar
 * em outro provedor, outro domínio, sem afetar o envio das notificações.
 *
 * Setting: `ramon-mail-reply.reply_address` — endereço completo, ex.:
 *   inbox@reply.meuforum.com
 *
 * Decomposto em (local_part, domain), o alias gerado no Reply-To fica:
 *   inbox+u42d123-{sig}@reply.meuforum.com
 */
class ReplyAddressConfig
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * Decompõe `reply_address` em (local, domain). Se o admin colou um
     * "+tag" no endereço configurado, descartamos a tag — senão o alias
     * gerado viraria "inbox+x+token@…".
     *
     * @return array{local: string, domain: string}|null
     */
    public function parts(): ?array
    {
        $address = trim((string) $this->settings->get('ramon-mail-reply.reply_address'));

        if ($address === '' || ! str_contains($address, '@')) {
            return null;
        }

        $at = strrpos($address, '@');
        $local = substr($address, 0, $at);
        $domain = substr($address, $at + 1);

        if ($local === '' || $domain === '') {
            return null;
        }

        if (($plus = strpos($local, '+')) !== false) {
            $local = substr($local, 0, $plus);
        }

        return ['local' => $local, 'domain' => $domain];
    }

    public function requireParts(): array
    {
        $parts = $this->parts();

        if ($parts === null) {
            throw new RuntimeException(
                'Mail Reply address is not configured. Set "Reply mailbox" under Admin → Extensions → Mail Reply.',
            );
        }

        return $parts;
    }

    /**
     * Endereço base que o admin precisa garantir no servidor IMAP. É também
     * o endereço a configurar como usuário IMAP — toda mensagem com qualquer
     * "+algo" cai aqui se o provedor honra sub-addressing.
     */
    public function baseAddress(): ?string
    {
        $parts = $this->parts();

        return $parts === null ? null : $parts['local'].'@'.$parts['domain'];
    }
}
