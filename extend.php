<?php

namespace Ramon\MailReply;

use Flarum\Api\Resource\PostResource;
use Flarum\Extend;
use Illuminate\Mail\Events\MessageSending;
use Ramon\MailReply\Api\Controller\InboundEmailController;
use Ramon\MailReply\Api\ExposeViaMailReply;
use Ramon\MailReply\Console\ProcessInboundEmailCommand;
use Ramon\MailReply\Listener\InjectReplyToHeader;

return [
    (new Extend\Routes('api'))
        ->post('/mail-reply/inbound', 'mail-reply.inbound', InboundEmailController::class),

    (new Extend\Event())
        ->listen(MessageSending::class, InjectReplyToHeader::class),

    (new Extend\Console())
        ->command(ProcessInboundEmailCommand::class),

    (new Extend\ApiResource(PostResource::class))
        ->fields(ExposeViaMailReply::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Settings())
        ->serializeToForum('mailReply.domain', 'ramon-mail-reply.domain')
        ->serializeToForum('mailReply.showBadge', 'ramon-mail-reply.show_badge', fn ($value) => (bool) ($value ?? true))
        ->default('ramon-mail-reply.strip_signatures', true)
        ->default('ramon-mail-reply.show_badge', true)
        ->default('ramon-mail-reply.token_ttl_days', 30),

    (new Extend\Locales(__DIR__.'/locale')),
];
