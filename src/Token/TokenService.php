<?php

namespace Ramon\MailReply\Token;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Ramon\MailReply\Model\ReplyToken;

class TokenService
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * Creates a token bound to a user/discussion pair and persists it.
     * The wire format is "<random>.<hmac>" so the token is self-authenticating
     * even before we look at the database.
     */
    public function create(int $userId, int $discussionId, ?int $postId = null): ReplyToken
    {
        $random = bin2hex(random_bytes(8));
        $payload = $userId.':'.$discussionId.':'.$random;
        $signature = $this->sign($payload);
        $wire = $random.'.'.substr($signature, 0, 16);

        $ttl = (int) ($this->settings->get('ramon-mail-reply.token_ttl_days') ?? 30);

        $model = new ReplyToken();
        $model->token = $wire;
        $model->user_id = $userId;
        $model->discussion_id = $discussionId;
        $model->post_id = $postId;
        $model->created_at = Carbon::now();
        $model->expires_at = $ttl > 0 ? Carbon::now()->addDays($ttl) : null;
        $model->save();

        return $model;
    }

    /**
     * Resolves a token string coming from an inbound email address.
     * Returns null if unknown, expired, already used, or signature mismatch.
     */
    public function resolve(string $raw): ?ReplyToken
    {
        $raw = trim($raw);

        if ($raw === '' || ! str_contains($raw, '.')) {
            return null;
        }

        /** @var ReplyToken|null $token */
        $token = ReplyToken::query()->where('token', $raw)->first();

        if ($token === null) {
            return null;
        }

        if ($token->used_at !== null) {
            return null;
        }

        if ($token->isExpired()) {
            return null;
        }

        // Re-verify the HMAC so a leaked DB row alone is not enough —
        // the APP_KEY must also match.
        [$random, $mac] = explode('.', $raw, 2);
        $expected = substr($this->sign($token->user_id.':'.$token->discussion_id.':'.$random), 0, 16);

        if (! hash_equals($expected, $mac)) {
            return null;
        }

        return $token;
    }

    public function markUsed(ReplyToken $token): void
    {
        $token->used_at = Carbon::now();
        $token->save();
    }

    /**
     * Extracts "TOKEN" from addresses like `reply+TOKEN@reply.example.com`
     * or `Forum <reply+TOKEN@reply.example.com>`.
     */
    public function extractFromAddress(string $address): ?string
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

        [, $token] = explode('+', $local, 2);

        return $token !== '' ? $token : null;
    }

    protected function sign(string $payload): string
    {
        $key = $this->settings->get('ramon-mail-reply.secret') ?: (getenv('APP_KEY') ?: 'flarum-mail-reply');

        return hash_hmac('sha256', $payload, $key);
    }
}
