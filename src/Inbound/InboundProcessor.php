<?php

namespace Ramon\MailReply\Inbound;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Ramon\MailReply\Parser\EmailParser;
use Ramon\MailReply\Token\TokenService;
use RuntimeException;

/**
 * Handles one inbound email payload end-to-end: validate token, parse body,
 * enforce permissions, create the reply post, and update discussion metadata.
 */
class InboundProcessor
{
    public function __construct(
        protected TokenService $tokens,
        protected EmailParser $parser,
        protected SettingsRepositoryInterface $settings,
        protected Dispatcher $events,
    ) {
    }

    /**
     * @param array{to: string|array<int,string>, from?: string, subject?: string, body_text?: string, body_html?: string, headers?: array<string,string>} $payload
     */
    public function process(array $payload): CommentPost
    {
        $tokenString = $this->findToken($payload);

        if ($tokenString === null) {
            throw new RuntimeException('No reply token could be extracted from the recipient address.');
        }

        $token = $this->tokens->resolve($tokenString);

        if ($token === null) {
            throw new RuntimeException('Unknown, expired, or already-used reply token.');
        }

        /** @var User|null $user */
        $user = User::query()->find($token->user_id);

        if ($user === null) {
            throw new RuntimeException('Token references a user that no longer exists.');
        }

        /** @var Discussion|null $discussion */
        $discussion = Discussion::query()->find($token->discussion_id);

        if ($discussion === null) {
            throw new RuntimeException('Token references a discussion that no longer exists.');
        }

        if (! $user->can('reply', $discussion)) {
            throw new RuntimeException('User is not allowed to reply to this discussion.');
        }

        $rawBody = $payload['body_text'] ?? $payload['body_html'] ?? '';
        $stripSignatures = (bool) ($this->settings->get('ramon-mail-reply.strip_signatures') ?? true);
        $content = $this->parser->extractReply($rawBody, $stripSignatures);

        if ($content === '') {
            throw new RuntimeException('Reply body is empty after parsing.');
        }

        $post = $this->createPost($user, $discussion, $content);

        $this->tokens->markUsed($token);

        return $post;
    }

    protected function findToken(array $payload): ?string
    {
        $to = $payload['to'] ?? null;

        $candidates = is_array($to) ? $to : [$to];

        foreach ($candidates as $address) {
            if (! is_string($address) || $address === '') {
                continue;
            }

            if ($token = $this->tokens->extractFromAddress($address)) {
                return $token;
            }
        }

        return null;
    }

    protected function createPost(User $user, Discussion $discussion, string $content): CommentPost
    {
        $post = new CommentPost();
        $post->created_at = Carbon::now();
        $post->discussion_id = $discussion->id;
        $post->user_id = $user->id;
        $post->type = CommentPost::$type;
        $post->setContentAttribute($content, $user);
        $post->number = ($discussion->last_post_number ?? 0) + 1;
        $post->via_mail_reply = true;

        $this->events->dispatch(new Saving($post, $user, ['attributes' => ['content' => $content]]));

        $post->save();

        $discussion->setLastPost($post);
        $discussion->refreshCommentCount();
        $discussion->refreshParticipantCount();
        $discussion->save();

        return $post;
    }
}
