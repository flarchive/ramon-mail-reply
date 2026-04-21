<?php

namespace Ramon\MailReply\Listener;

use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Mail\Events\MessageSending;
use Ramon\MailReply\Token\TokenService;

/**
 * Inspects every outgoing notification email; when the blueprint carries a
 * discussion/post context and a single recipient, generates a per-user,
 * per-discussion reply token and injects it as a Reply-To header.
 *
 * Result in the inbox: replying to the mail lands on our webhook/command
 * with enough info to post back into the discussion as that user.
 */
class InjectReplyToHeader
{
    public function __construct(
        protected TokenService $tokens,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(MessageSending $event): bool
    {
        $domain = trim((string) $this->settings->get('ramon-mail-reply.domain'));

        if ($domain === '') {
            return true;
        }

        $user = $event->data['user'] ?? null;
        $blueprint = $event->data['blueprint'] ?? null;

        if ($user === null || $blueprint === null) {
            return true;
        }

        $discussionId = $this->extractDiscussionId($blueprint);

        if ($discussionId === null) {
            return true;
        }

        $postId = $this->extractPostId($blueprint);

        $token = $this->tokens->create((int) $user->id, $discussionId, $postId);

        $replyAddress = 'reply+'.$token->token.'@'.$domain;

        $headers = $event->message->getHeaders();
        $headers->remove('Reply-To');
        $headers->addMailboxListHeader('Reply-To', [$replyAddress]);

        return true;
    }

    protected function extractDiscussionId(object $blueprint): ?int
    {
        // PostMentioned, Replied, NewPost… all carry a Post via getSubject()
        // or through a public $post property. Normalise both.
        if (method_exists($blueprint, 'getSubject')) {
            $subject = $blueprint->getSubject();

            if ($subject instanceof Post) {
                return (int) $subject->discussion_id;
            }

            if (is_object($subject) && isset($subject->discussion_id)) {
                return (int) $subject->discussion_id;
            }

            if (is_object($subject) && isset($subject->id) && method_exists($subject, 'getTable') && $subject->getTable() === 'discussions') {
                return (int) $subject->id;
            }
        }

        if (property_exists($blueprint, 'post') && $blueprint->post instanceof Post) {
            return (int) $blueprint->post->discussion_id;
        }

        return null;
    }

    protected function extractPostId(object $blueprint): ?int
    {
        if (method_exists($blueprint, 'getSubject')) {
            $subject = $blueprint->getSubject();

            if ($subject instanceof Post) {
                return (int) $subject->id;
            }
        }

        if (property_exists($blueprint, 'post') && $blueprint->post instanceof Post) {
            return (int) $blueprint->post->id;
        }

        return null;
    }
}
