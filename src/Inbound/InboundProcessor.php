<?php

namespace Ramon\MailReply\Inbound;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Database\MailReplyPost;
use Ramon\MailReply\Parser\EmailParser;
use Ramon\MailReply\Token\TokenService;
use RuntimeException;

/**
 * Processa um payload de inbound de ponta a ponta: extrai (user_id,
 * discussion_id) do endereço assinado, valida permissão de reply, e cria o
 * post numa transação — número e contadores são atribuídos pelo próprio
 * Flarum core (Post::boot creating + Posted listener).
 */
class InboundProcessor
{
    use DispatchEventsTrait;

    public function __construct(
        protected TokenService $tokens,
        protected EmailParser $parser,
        protected SettingsRepositoryInterface $settings,
        protected Dispatcher $events,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Defesa em profundidade: `whereVisibleTo($user)` antes do policy
     * check. Cobre cenários onde o tópico/tag virou restrito DEPOIS da
     * notificação ter sido enviada — o whereVisibleTo é a barreira
     * oficial do core para IDOR em modelos com ScopeVisibility.
     *
     * Discussões privadas (fof/byobu `is_private = true`) são bloqueadas
     * por design: replies por e-mail só fazem sentido em conteúdo
     * público. Aliases para threads privadas nem chegam a ser gerados
     * (CombinedEmailMutator também bloqueia no outbound).
     *
     * Quando o alias carrega um postId (notificação sobre um post
     * específico — mention/reply/post mention), prepende o post com
     * `@"author"#p{postId}` para que o fórum renderize a resposta como
     * reply ao post original, igual ao botão "Responder" da UI. Se o
     * alias não tem postId (DiscussionStarted etc.) ou se não conseguimos
     * resolver o autor, segue só com o conteúdo nu.
     *
     * @param array{to: string|array<int,string>, from?: string, subject?: string, body_text?: string, body_html?: string, headers?: array<string,string>} $payload
     */
    public function process(array $payload): CommentPost
    {
        $bind = $this->findBinding($payload);

        if ($bind === null) {
            throw new RuntimeException('No valid signed reply address found among recipients.');
        }

        /** @var User|null $user */
        $user = User::query()->find($bind['user_id']);

        if ($user === null) {
            throw new RuntimeException('Signed address references a user that no longer exists.');
        }

        /** @var Discussion|null $discussion */
        $discussion = Discussion::query()
            ->whereVisibleTo($user)
            ->find($bind['discussion_id']);

        if ($discussion === null) {
            throw new RuntimeException('Signed address references a discussion that no longer exists or is not visible to the user.');
        }

        if ($this->isPrivateDiscussion($discussion)) {
            throw new RuntimeException('Replies via email are only supported on public discussions; private/direct-message threads are blocked by design.');
        }

        if (! $user->can('reply', $discussion)) {
            throw new RuntimeException('User is not allowed to reply to this discussion.');
        }

        $rawText = (string) ($payload['body_text'] ?? '');
        $rawHtml = (string) ($payload['body_html'] ?? '');
        $rawBody = $rawText !== '' ? $rawText : $rawHtml;
        $content = $this->parser->extractReply($rawBody, true);

        if ($content === '') {
            $this->logger->warning('[mail-reply] reply body empty after parsing', [
                'user_id' => $bind['user_id'] ?? null,
                'discussion_id' => $bind['discussion_id'] ?? null,
                'post_id' => $bind['post_id'] ?? null,
                'raw_text_len' => strlen($rawText),
                'raw_html_len' => strlen($rawHtml),
                'raw_text_sample' => substr($rawText, 0, 500),
                'raw_html_sample' => substr($rawHtml, 0, 500),
            ]);
            throw new RuntimeException('Reply body is empty after parsing.');
        }

        $content = $this->prependReplyQuote($content, $bind['post_id'] ?? null);

        return CommentPost::query()->getConnection()->transaction(function () use ($user, $discussion, $content) {
            return $this->createPost($user, $discussion, $content);
        });
    }

    /**
     * Mention syntax do flarum/mentions: `@"username"#p{postId}` renderiza
     * o post citado como bloco quotado no topo. Escapa aspas duplas e
     * backslash do username — usernames Flarum geralmente são limpos, mas
     * é defesa contra username artesanal/legado.
     */
    protected function prependReplyQuote(string $content, ?int $postId): string
    {
        if ($postId === null || $postId <= 0) {
            return $content;
        }

        /** @var Post|null $original */
        $original = Post::query()->find($postId);

        if ($original === null) {
            return $content;
        }

        $author = $original->user;

        if ($author === null) {
            return $content;
        }

        $username = trim((string) $author->username);

        if ($username === '') {
            return $content;
        }

        $username = str_replace(['\\', '"'], ['\\\\', '\\"'], $username);

        return sprintf('@"%s"#p%d', $username, $postId)."\n\n".$content;
    }

    /**
     * @return array{user_id: int, discussion_id: int, post_id: ?int}|null
     */
    protected function findBinding(array $payload): ?array
    {
        $to = $payload['to'] ?? null;

        $candidates = is_array($to) ? $to : [$to];

        foreach ($candidates as $address) {
            if (! is_string($address) || $address === '') {
                continue;
            }

            if ($bind = $this->tokens->parseAddress($address)) {
                return $bind;
            }
        }

        return null;
    }

    /**
     * Bloqueia threads privadas/direct-message. Cobre o `is_private` do
     * fof/byobu sem exigir que o pacote esteja instalado: se a coluna não
     * existe, o atributo é null e tratamos como público. Mesmo padrão de
     * defesa serve para qualquer extensão futura que use a mesma flag.
     */
    protected function isPrivateDiscussion(Discussion $discussion): bool
    {
        return (bool) ($discussion->is_private ?? false);
    }

    /**
     * Cria o post deixando o core fazer o trabalho atômico — `Post::boot
     * creating` atribui `number` via MAX(number)+1 no banco, e o Posted
     * listener (DiscussionMetadataUpdater) atualiza last_post,
     * comment_count e participant_count após o save.
     *
     * A marca "via mail reply" vai numa companion table em vez de uma
     * coluna em `posts` (extensão jamais ALTERa tabela do core). Falha
     * na criação da row companion não derruba o reply — o post fica
     * salvo, apenas perde o badge.
     */
    protected function createPost(User $user, Discussion $discussion, string $content): CommentPost
    {
        $post = new CommentPost();
        $post->created_at = Carbon::now();
        $post->discussion_id = $discussion->id;
        $post->user_id = $user->id;
        $post->setContentAttribute($content, $user);

        $this->events->dispatch(new Saving($post, $user, ['attributes' => ['content' => $content]]));

        $post->save();

        try {
            $meta = new MailReplyPost();
            $meta->post_id = (int) $post->id;
            $meta->save();
        } catch (\Throwable $e) {
            $this->logger->warning('[mail-reply] failed to persist mail_reply_posts row', [
                'post_id' => (int) $post->id,
                'reason' => $e->getMessage(),
            ]);
        }

        $this->dispatchEventsFor($post, $user);

        return $post;
    }
}
