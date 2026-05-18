<?php

namespace Ramon\MailReply\Console;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ramon\MailReply\Mail\ReplyAddressConfig;
use Ramon\MailReply\Token\TokenService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnóstico ponta-a-ponta: imprime o endereço aliasado que seria gerado
 * para (usuário, tópico), faz o round-trip de parse e valida configuração.
 *
 *     php flarum mail-reply:diagnose <userId> <discussionId>
 *     php flarum mail-reply:diagnose --address=ramon+u42d123-...@ramonguilherme.com.br
 */
#[AsCommand(name: 'mail-reply:diagnose', description: 'Mint and parse a reply alias for a given user+discussion to verify the mail-reply setup.')]
class DiagnoseMailReplyCommand extends Command
{
    public function __construct(
        protected TokenService $tokens,
        protected ReplyAddressConfig $replyAddress,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('user', InputArgument::OPTIONAL, 'Numeric user ID.');
        $this->addArgument('discussion', InputArgument::OPTIONAL, 'Numeric discussion ID.');
        $this->addArgument('post', InputArgument::OPTIONAL, 'Optional post ID for quote-prepend (mention/reply notifications).');
        $this->addOption('address', null, InputOption::VALUE_REQUIRED, 'Inverse mode: parse an address and report user/discussion/post.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $address = $input->getOption('address');

        if ($address !== null) {
            return $this->describeAddress($output, (string) $address);
        }

        $userArg = $input->getArgument('user');
        $discArg = $input->getArgument('discussion');
        $postArg = $input->getArgument('post');

        if ($userArg === null || $discArg === null) {
            $output->writeln('<error>Provide <user> <discussion> [<post>] arguments, or --address=<full reply address>.</error>');

            return Command::INVALID;
        }

        $postId = $postArg !== null ? (int) $postArg : null;

        return $this->describePair($output, (int) $userArg, (int) $discArg, $postId);
    }

    protected function describePair(OutputInterface $output, int $userId, int $discussionId, ?int $postId = null): int
    {
        $base = $this->replyAddress->baseAddress();

        if ($base === null) {
            $output->writeln('<error>Reply mailbox not configured. Set "Reply mailbox" under Admin → Extensions → Mail Reply before testing.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Reply mailbox (base):</info> '.$base);

        $user = User::query()->find($userId);
        $discussion = Discussion::query()->find($discussionId);

        $output->writeln('<info>User #'.$userId.':</info> '.($user ? $user->username : '<error>NOT FOUND</error>'));
        $output->writeln('<info>Discussion #'.$discussionId.':</info> '.($discussion ? $discussion->title.' (slug: '.($discussion->slug ?? '—').')' : '<error>NOT FOUND</error>'));

        if ($user && $discussion) {
            $output->writeln('<info>Can reply:</info> '.($user->can('reply', $discussion) ? 'yes' : '<error>NO — permission denied</error>'));
        }

        $aliasAddress = $this->tokens->mintAddress($userId, $discussionId, $postId);

        $output->writeln('');
        $output->writeln('<info>Reply-To address (added to notifications, captured by IMAP):</info>');
        $output->writeln('  '.$aliasAddress);
        $output->writeln('<info>IMAP base mailbox (must capture +alias):</info>');
        $output->writeln('  '.$base);

        if ($postId !== null && $postId > 0) {
            $output->writeln('<info>Quote-prepend on reply:</info>');
            $output->writeln('  @"<author of post #'.$postId.'>"#p'.$postId);
        } else {
            $output->writeln('<info>Quote-prepend on reply:</info> (none — no postId in alias)');
        }

        $output->writeln('');

        $roundTrip = $this->tokens->parseAddress($aliasAddress ?? '');
        $output->writeln('<info>Round-trip parse:</info> '.($roundTrip
            ? 'user='.$roundTrip['user_id'].' discussion='.$roundTrip['discussion_id'].' post='.($roundTrip['post_id'] ?? 'null').' OK'
            : '<error>FAILED — HMAC mismatch or format error</error>'));

        return $user && $discussion && $aliasAddress ? Command::SUCCESS : Command::FAILURE;
    }

    protected function describeAddress(OutputInterface $output, string $address): int
    {
        $output->writeln('<info>Input:</info> '.$address);

        $parsed = $this->tokens->parseAddress($address);

        if ($parsed === null) {
            $output->writeln('<error>Address is not a valid signed mail-reply alias.</error>');
            $output->writeln('Reasons: wrong local part prefix (reply_address mismatch), missing/invalid HMAC, or different secret on this server.');

            return Command::FAILURE;
        }

        $output->writeln('<info>user_id:</info> '.$parsed['user_id']);
        $output->writeln('<info>discussion_id:</info> '.$parsed['discussion_id']);
        $output->writeln('<info>post_id:</info> '.($parsed['post_id'] ?? '(none)'));

        $user = User::query()->find($parsed['user_id']);
        $discussion = Discussion::query()->find($parsed['discussion_id']);

        $output->writeln('<info>User:</info> '.($user ? $user->username : '<error>NOT FOUND</error>'));
        $output->writeln('<info>Discussion:</info> '.($discussion ? $discussion->title : '<error>NOT FOUND</error>'));

        if ($user && $discussion) {
            $output->writeln('<info>Can reply:</info> '.($user->can('reply', $discussion) ? 'yes' : '<error>NO — permission denied</error>'));
        }

        return Command::SUCCESS;
    }
}
