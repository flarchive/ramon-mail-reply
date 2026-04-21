<?php

namespace Ramon\MailReply\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema\Boolean;
use Flarum\Post\Post;

/**
 * Callback consumed by Extend\ApiResource(PostResource::class)->fields(...).
 * Exposes a read-only `viaMailReply` boolean so the forum JS can show a badge.
 */
class ExposeViaMailReply
{
    public function __invoke(): array
    {
        return [
            Boolean::make('viaMailReply')
                ->get(fn (Post $post) => (bool) ($post->via_mail_reply ?? false)),
        ];
    }
}
