<?php

namespace Ramon\MailReply\Console;

use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Ramon\MailReply\Mail\ReplyAddressConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Dispara um MessageSending sintético para ver se o listener efetivamente
 * adiciona o header Reply-To com o alias. Útil para diagnosticar:
 *
 *   - "Os e-mails saem sem Reply-To" → roda isto. Se aqui aparece OK, o
 *     problema é queue worker rodando código antigo (reinicie).
 *   - "Tudo certo aqui mas no e-mail real continua sem Reply-To" → cache
 *     stale (php flarum cache:clear) ou listener desativado.
 *
 *     php flarum mail-reply:simulate-send <userId> <discussionId>
 */
#[AsCommand(name: 'mail-reply:simulate-send', description: 'Synthesize a notification email and dispatch MessageSending to verify Reply-To injection end-to-end.')]
class SimulateSendCommand extends Command
{
    public function __construct(
        protected Dispatcher $events,
        protected ReplyAddressConfig $replyAddress,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('user', InputArgument::REQUIRED, 'Recipient user ID.');
        $this->addArgument('discussion', InputArgument::REQUIRED, 'Discussion ID the synthetic notification refers to.');
    }

    /**
     * Usa `until()` (não `dispatch()`) para refletir EXATAMENTE o que o
     * Laravel Mailer faz em `Mailer::shouldSendMessage()`. Isso também
     * valida que o rebind de MutateEmail → CombinedEmailMutator está
     * ativo: sem o rebind, o MutateEmail original do core resolveria e
     * não adicionaria Reply-To, mesmo com nosso código deployado.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = (int) $input->getArgument('user');
        $discussionId = (int) $input->getArgument('discussion');

        $replyBase = $this->replyAddress->baseAddress();

        if ($replyBase === null) {
            $output->writeln('<error>Reply mailbox not configured. Set "Reply mailbox" under Admin → Extensions → Mail Reply first.</error>');

            return Command::FAILURE;
        }

        $mailFrom = trim((string) $this->settings->get('mail_from'));

        if ($mailFrom === '' || ! str_contains($mailFrom, '@')) {
            $mailFrom = 'simulator@example.com';
        }

        /** @var User|null $user */
        $user = User::query()->find($userId);

        if ($user === null) {
            $output->writeln('<error>User #'.$userId.' not found.</error>');

            return Command::FAILURE;
        }

        /** @var Discussion|null $discussion */
        $discussion = Discussion::query()->find($discussionId);

        if ($discussion === null) {
            $output->writeln('<error>Discussion #'.$discussionId.' not found.</error>');

            return Command::FAILURE;
        }

        $post = new CommentPost();
        $post->discussion_id = $discussion->id;
        $post->user_id = $user->id;
        $post->exists = false;

        $blueprint = new SyntheticBlueprint($post);

        $email = (new Email())
            ->from(new Address($mailFrom, 'mail-reply simulator'))
            ->to(new Address($user->email ?: 'test@example.com', $user->display_name ?: 'recipient'))
            ->subject('[simulate-send] '.$discussion->title)
            ->text('synthetic body');

        $beforeFrom = $this->firstAddressString($email->getFrom());
        $beforeReplyTo = $email->getHeaders()->get('Reply-To')?->getBodyAsString();

        $output->writeln('<info>Before listener:</info>');
        $output->writeln('  From:     '.$beforeFrom);
        $output->writeln('  Reply-To: '.($beforeReplyTo ?: '(none)'));
        $output->writeln('');

        $this->events->until(new MessageSending($email, [
            'blueprint' => $blueprint,
            'user' => $user,
        ]));

        $afterFrom = $this->firstAddressString($email->getFrom());
        $afterReplyTo = $email->getHeaders()->get('Reply-To')?->getBodyAsString();

        $output->writeln('<info>After listener:</info>');
        $output->writeln('  From:     '.$afterFrom.'  (unchanged — by design)');
        $output->writeln('  Reply-To: '.($afterReplyTo ?: '(none)'));
        $output->writeln('  Reply mailbox base (IMAP target): '.$replyBase);
        $output->writeln('');

        $injected = $beforeReplyTo !== $afterReplyTo && $afterReplyTo !== null;

        if ($injected) {
            $output->writeln('<info>✓ Listener added Reply-To with alias.</info>');
            $output->writeln('If real notifications still come out without the Reply-To, restart the queue worker — it likely has the old class cached.');

            return Command::SUCCESS;
        }

        $output->writeln('<error>✗ Listener did NOT add Reply-To. Possible causes:</error>');
        $output->writeln('  1. Extension is disabled — run: php flarum extension:enable ramon-mail-reply');
        $output->writeln('  2. Boot cache is stale — run: php flarum cache:clear');
        $output->writeln('  3. Conflict: another extension overrides MessageSending after ours');
        $output->writeln('  4. Discussion subject extraction failed (blueprint check) — see logs');

        return Command::FAILURE;
    }

    /**
     * @param array<int, Address> $addresses
     */
    protected function firstAddressString(array $addresses): string
    {
        foreach ($addresses as $addr) {
            $name = $addr->getName();
            $email = $addr->getAddress();

            return $name !== '' ? sprintf('%s <%s>', $name, $email) : $email;
        }

        return '(none)';
    }
}
