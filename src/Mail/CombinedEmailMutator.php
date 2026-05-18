<?php

namespace Ramon\MailReply\Mail;

use Flarum\Discussion\Discussion;
use Flarum\Foundation\ErrorHandling\LogReporter;
use Flarum\Mail\MutateEmail;
use Flarum\Post\Post;
use Illuminate\Mail\Events\MessageSending;
use Psr\Log\LoggerInterface;
use Ramon\MailReply\Token\TokenService;
use Throwable;

/**
 * Workaround para o `events->until()` do Laravel Mailer.
 *
 * Laravel dispara `MessageSending` via `until()` (Mailer.php:603 em
 * vendor/illuminate/mail), que PARA no primeiro listener que retornar
 * valor não-null. `Flarum\Mail\MutateEmail::handle()` do core retorna
 * `true` para adicionar `List-Unsubscribe` e portanto bloqueia qualquer
 * listener subsequente — incluindo o nosso.
 *
 * Como `MailServiceProvider::boot()` do core registra MutateEmail antes
 * de qualquer extend.php ser lido, é impossível para uma extensão
 * registrar um listener que rode antes. Por isso ESTENDEMOS o
 * MutateEmail e reusamos a resolução via container: ao fazer
 * `$container->bind(MutateEmail::class, CombinedEmailMutator::class)` no
 * ServiceProvider, o Dispatcher do Laravel resolve essa classe ao invés
 * da original em `Dispatcher::createClassCallable`, porque ele chama
 * `$this->container->make($class)`.
 *
 * Resultado: nossa lógica de Reply-To roda no MESMO callback do core,
 * com acesso ao mesmo `$event->data` (que inclui blueprint + user), sem
 * ordem de listener importar.
 */
class CombinedEmailMutator extends MutateEmail
{
    /**
     * Memoização por-request do flag `is_private` por discussion_id.
     * Mailer dispara MessageSending múltiplas vezes em um batch de
     * notificação; evitar query repetida pra mesma discussion.
     *
     * @var array<int, bool>
     */
    protected array $privateCache = [];

    public function __construct(
        protected TokenService $tokens,
        protected LogReporter $log,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * 1) Comportamento original do core: adicionar List-Unsubscribe quando
     * o blueprint forneceu unsubscribeLink. 2) Nossa lógica: adicionar
     * Reply-To com alias assinado por HMAC. Sempre devolve true para
     * manter o contrato original.
     */
    public function handle(MessageSending $event): bool
    {
        parent::handle($event);

        try {
            $this->injectReplyTo($event);
        } catch (Throwable $e) {
            $this->log->report($e);
        }

        return true;
    }

    /**
     * Quando o blueprint carrega um post específico (mention, reply, post
     * mention), embutimos o postId no alias para que o InboundProcessor
     * monte o `@"author"#p{postId}` no início da resposta — replicando o
     * "Responder ao post" do fórum.
     *
     * Algumas extensões passam blueprint/user como array ou outro tipo;
     * nosso fluxo só faz sentido com objetos.
     */
    protected function injectReplyTo(MessageSending $event): void
    {
        $data = (array) ($event->data ?? []);
        $user = $data['user'] ?? null;
        $blueprint = $data['blueprint'] ?? null;

        $this->logger->info('[mail-reply] MessageSending observed', [
            'has_user' => is_object($user),
            'has_blueprint' => is_object($blueprint),
            'blueprint' => is_object($blueprint) ? get_class($blueprint) : null,
            'user_id' => is_object($user) ? ($user->id ?? null) : null,
        ]);

        if (! is_object($user) || ! is_object($blueprint)) {
            return;
        }

        $discussionId = $this->extractDiscussionId($blueprint);

        if ($discussionId === null) {
            $this->logger->warning('[mail-reply] skip: blueprint has no discussion', [
                'blueprint' => get_class($blueprint),
                'user_id' => $user->id ?? null,
            ]);
            return;
        }

        if ($this->isPrivateDiscussionId($discussionId)) {
            $this->logger->info('[mail-reply] skip: private discussion (mail-reply is public-only)', [
                'discussion_id' => $discussionId,
                'blueprint' => get_class($blueprint),
                'user_id' => $user->id ?? null,
            ]);
            return;
        }

        $postId = $this->extractPostId($blueprint);

        $aliasAddress = $this->tokens->mintAddress((int) $user->id, $discussionId, $postId);

        if ($aliasAddress === null) {
            $this->logger->warning('[mail-reply] skip: reply_address NOT CONFIGURED — set Admin → Extensions → Mail Reply → "Reply mailbox"', [
                'user_id' => $user->id ?? null,
                'discussion_id' => $discussionId,
                'post_id' => $postId,
            ]);
            return;
        }

        $message = $event->message;
        $headers = $message->getHeaders();
        $headers->remove('Reply-To');
        $headers->addMailboxListHeader('Reply-To', [$aliasAddress]);

        if ($postId !== null && $postId > 0) {
            $this->ensureSubjectHasPostId($message, $postId);
        }

        $this->logger->info('[mail-reply] added Reply-To alias', [
            'user_id' => (int) $user->id,
            'discussion_id' => $discussionId,
            'post_id' => $postId,
            'reply_to' => $aliasAddress,
            'blueprint' => get_class($blueprint),
        ]);
    }

    /**
     * Quebra agrupamento por thread no cliente (Outlook, Gmail web, Apple
     * Mail). Esses clientes agrupam por Subject idêntico — se o usuário
     * recebe 4 notificações sobre o mesmo tópico, todas com "Ramon
     * mentioned you in 'X'", elas viram UMA conversa e o botão Responder
     * usa o Reply-To do PRIMEIRO e-mail, não do mais recente. Adicionar
     * `#{postId}` no final torna cada Subject único; cada e-mail vira sua
     * própria conversa e o Reply-To respeitado é o do próprio e-mail
     * aberto. Idempotente caso o evento seja re-enfileirado.
     */
    protected function ensureSubjectHasPostId(object $message, int $postId): void
    {
        if (! method_exists($message, 'getSubject') || ! method_exists($message, 'subject')) {
            return;
        }

        $currentSubject = (string) $message->getSubject();
        $marker = ' #'.$postId;

        if (str_ends_with($currentSubject, $marker) || str_contains($currentSubject, $marker.' ') || str_contains($currentSubject, $marker.']')) {
            return;
        }

        $message->subject($currentSubject.$marker);
    }

    /**
     * Consulta `discussions.is_private` (fof/byobu) com memoização por
     * request. Em hosts sem byobu a coluna não existe; capturamos a
     * exception e tratamos como público — o catch também serve como
     * proteção pra discussions deletadas (find devolve null).
     */
    protected function isPrivateDiscussionId(int $discussionId): bool
    {
        if (isset($this->privateCache[$discussionId])) {
            return $this->privateCache[$discussionId];
        }

        try {
            $isPrivate = (bool) Discussion::query()
                ->where('id', $discussionId)
                ->value('is_private');
        } catch (Throwable $e) {
            $isPrivate = false;
        }

        return $this->privateCache[$discussionId] = $isPrivate;
    }

    /**
     * Tenta extrair discussion_id do blueprint. Usa `getSubject()` como
     * fonte primária (canônica do Flarum), com fallback pra propriedade
     * `$post` pública para blueprints atípicos.
     */
    protected function extractDiscussionId(object $blueprint): ?int
    {
        try {
            if (method_exists($blueprint, 'getSubject')) {
                $subject = $blueprint->getSubject();

                if ($subject instanceof Post && (int) $subject->discussion_id > 0) {
                    return (int) $subject->discussion_id;
                }

                if (is_object($subject) && method_exists($subject, 'getTable') && $subject->getTable() === 'discussions' && isset($subject->id)) {
                    return (int) $subject->id;
                }

                if (is_object($subject) && isset($subject->discussion_id)) {
                    return (int) $subject->discussion_id;
                }
            }

            if (property_exists($blueprint, 'post')) {
                $post = $blueprint->post;
                if ($post instanceof Post && (int) $post->discussion_id > 0) {
                    return (int) $post->discussion_id;
                }
            }
        } catch (Throwable $e) {
            $this->log->report($e);
        }

        return null;
    }

    /**
     * Extrai o postId que deve ser o "alvo" do quote-prepend — o post que
     * o usuário está respondendo ao replicar essa notificação.
     *
     * Regras (ordem importa):
     *
     * 1. `PostMentionedBlueprint` (flarum/mentions) tem DUAS Post
     *    properties: `$post` (post CITADO — o antigo) e `$reply` (post
     *    NOVO de quem te citou). `getSubject()` retorna `$post` porque
     *    semanticamente é o "subject" da notif, MAS pra nosso quote
     *    queremos `$reply` — você está respondendo a quem te citou, não
     *    ao seu próprio post antigo. Detectamos esse caso pela presença
     *    das duas propriedades.
     *
     * 2. `UserMentionedBlueprint`, `GroupMentionedBlueprint` — só têm
     *    `$post` (o post que menciona). `getSubject()` retorna `$post`
     *    corretamente. Caímos no caso geral.
     *
     * 3. `NewPostBlueprint` (subscriptions) — `getSubject()` retorna
     *    Discussion. Devolvemos null → alias sem `p{N}` → sem quote.
     */
    protected function extractPostId(object $blueprint): ?int
    {
        try {
            if (property_exists($blueprint, 'reply') && property_exists($blueprint, 'post')) {
                $reply = $blueprint->reply;
                if ($reply instanceof Post && (int) $reply->id > 0) {
                    return (int) $reply->id;
                }
            }

            if (method_exists($blueprint, 'getSubject')) {
                $subject = $blueprint->getSubject();
                if ($subject instanceof Post && (int) $subject->id > 0) {
                    return (int) $subject->id;
                }
            }
        } catch (Throwable $e) {
            $this->log->report($e);
        }

        return null;
    }
}
