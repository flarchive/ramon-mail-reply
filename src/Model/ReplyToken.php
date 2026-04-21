<?php

namespace Ramon\MailReply\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $token
 * @property int $user_id
 * @property int $discussion_id
 * @property int|null $post_id
 * @property Carbon $created_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $used_at
 */
class ReplyToken extends AbstractModel
{
    protected $table = 'mail_reply_tokens';

    protected $primaryKey = 'token';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $dates = ['created_at', 'expires_at', 'used_at'];

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
