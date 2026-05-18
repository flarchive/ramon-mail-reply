<?php

namespace Ramon\MailReply\Api;

use Flarum\Api\Schema\Boolean;
use Flarum\Post\Post;
use Throwable;

/**
 * Callback consumed by Extend\ApiResource(PostResource::class)->fields(...).
 * Exposes a read-only `viaMailReply` boolean so the forum JS can show a badge.
 *
 * A presença da relação `mailReplyMeta` (companion 1:1) é a fonte de
 * verdade — substitui a antiga coluna `posts.via_mail_reply`. Em
 * listings, a relação não está eager-loaded por padrão; o getter dispara
 * 1 query por post somente quando o badge precisa ser renderizado. O JS
 * front-end já cacheia o status `mailReply.showBadge` para evitar custo
 * quando o admin desligou o badge.
 *
 * Defensivo contra a janela de migração: se a tabela `mail_reply_posts`
 * ainda não existe (migrations pendentes), devolve `false` em vez de
 * derrubar a serialização do post inteiro. O log já fala — repete aqui
 * causaria ruído por post em listings.
 */
class ExposeViaMailReply
{
    public function __invoke(): array
    {
        return [
            Boolean::make('viaMailReply')
                ->get(function (Post $post) {
                    try {
                        return $post->mailReplyMeta()->exists();
                    } catch (Throwable $e) {
                        return false;
                    }
                }),
        ];
    }
}
