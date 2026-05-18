<?php

namespace Ramon\MailReply;

use Flarum\Api\Resource\PostResource;
use Flarum\Extend;
use Flarum\Post\Post;
use Ramon\MailReply\Api\Controller\InboundEmailController;
use Ramon\MailReply\Api\Controller\TestImapController;
use Ramon\MailReply\Api\ExposeViaMailReply;
use Ramon\MailReply\Console\DiagnoseMailReplyCommand;
use Ramon\MailReply\Console\FetchInboundEmailCommand;
use Ramon\MailReply\Console\SimulateSendCommand;
use Ramon\MailReply\Database\MailReplyPost;

return [
    (new Extend\Routes('api'))
        ->post('/mail-reply/inbound', 'mail-reply.inbound', InboundEmailController::class)
        ->post('/mail-reply/test-imap', 'mail-reply.test-imap', TestImapController::class),

    /**
     * Webhook recebe POST de MTAs/scripts pipe externos sem cookie nem
     * CSRF token. A autenticação real é o header X-Mail-Reply-Token
     * verificado por hash_equals no controller.
     */
    (new Extend\Csrf())
        ->exemptRoute('mail-reply.inbound'),

    (new Extend\ServiceProvider())
        ->register(MailReplyServiceProvider::class),

    (new Extend\Console())
        ->command(FetchInboundEmailCommand::class)
        ->command(DiagnoseMailReplyCommand::class)
        ->command(SimulateSendCommand::class),

    (new Extend\Model(Post::class))
        ->hasOne('mailReplyMeta', MailReplyPost::class, 'post_id'),

    (new Extend\ApiResource(PostResource::class))
        ->fields(ExposeViaMailReply::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    /**
     * `delivery_mode` aceita 'imap' (poller dentro do queue:work), 'webhook'
     * (endpoint público + script PHP no MTA) ou 'disabled'. Apenas um ativo
     * por vez — o controller e o ServiceProvider checam o valor pra decidir
     * se aceitam POST ou se despacham o job de polling.
     */
    (new Extend\Settings())
        ->serializeToForum('mailReply.showBadge', 'ramon-mail-reply.show_badge', fn ($value) => (bool) ($value ?? true))
        ->default('ramon-mail-reply.delivery_mode', 'imap')
        ->default('ramon-mail-reply.show_badge', true)
        ->default('ramon-mail-reply.imap_port', 993)
        ->default('ramon-mail-reply.imap_encryption', 'ssl')
        ->default('ramon-mail-reply.imap_mailbox', 'INBOX')
        ->default('ramon-mail-reply.imap_delete_after', true)
        ->default('ramon-mail-reply.imap_validate_cert', true),

    (new Extend\Locales(__DIR__.'/locale')),
];
