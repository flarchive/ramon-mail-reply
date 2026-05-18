<?php

namespace Ramon\MailReply\Database;

use Flarum\Database\AbstractModel;
use Flarum\Post\Post;

/**
 * Companion 1:1 do `posts` — extensão jamais ALTERa tabela do core. A
 * simples existência da row marca o post como criado via
 * reply por e-mail; não há colunas adicionais por enquanto, mas a tabela
 * está pronta pra carregar metadata futura (provider, signature
 * algorithm, timestamps de inbound) sem mexer no `posts`.
 *
 * @property int $post_id
 */
class MailReplyPost extends AbstractModel
{
    protected $table = 'mail_reply_posts';
    protected $primaryKey = 'post_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $casts = ['post_id' => 'int'];

    public function post()
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
