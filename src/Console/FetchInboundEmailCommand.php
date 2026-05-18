<?php

namespace Ramon\MailReply\Console;

use Flarum\Settings\SettingsRepositoryInterface;
use Ramon\MailReply\Inbound\ImapFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Coleta mensagens não lidas da caixa de respostas via IMAP. Em produção
 * o ciclo é despachado automaticamente pelo `FetchInboundEmailsJob` dentro
 * do `queue:work` — este comando existe para diagnóstico manual ou para
 * operadores que preferem rodar via supervisor.
 *
 *     php flarum mail-reply:fetch --limit=10
 *     php flarum mail-reply:fetch --loop --interval=60
 */
#[AsCommand(name: 'mail-reply:fetch', description: 'Fetch unseen replies from the configured IMAP inbox and post them to Flarum.')]
class FetchInboundEmailCommand extends Command
{
    public function __construct(
        protected ImapFetcher $fetcher,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of messages to fetch per run.', '50');
        $this->addOption('verbose-status', null, InputOption::VALUE_NONE, 'Always print a status line (default: silent when IMAP not configured, useful for cron).');
        $this->addOption('loop', null, InputOption::VALUE_NONE, 'Run in a polling loop instead of returning after one fetch. Use with --interval.');
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds to wait between iterations when --loop is set.', '60');
    }

    /**
     * Modo loop é pensado para supervisor (systemd, supervisord) rodar
     * lado a lado com queue:work quando o operador não pode/quer configurar
     * cron. Falhas individuais não derrubam o loop.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $verbose = (bool) $input->getOption('verbose-status');
        $loop = (bool) $input->getOption('loop');
        $interval = max(5, (int) $input->getOption('interval'));

        if (! $loop) {
            return $this->runOnce($output, $limit, $verbose);
        }

        $output->writeln(sprintf('<info>mail-reply:fetch — loop mode, interval=%ds</info>', $interval));

        while (true) {
            try {
                $this->runOnce($output, $limit, $verbose);
            } catch (Throwable $e) {
                $output->writeln('<error>loop iteration failed: '.$e->getMessage().'</error>');
            }

            sleep($interval);
        }
    }

    /**
     * Respeita o modo de entrega configurado no admin: se não for 'imap',
     * o fetcher fica em standby — silencioso pra não poluir cron/loop com
     * falsos negativos. Sem IMAP configurado também sai silencioso e com
     * sucesso, senão o operador recebe e-mails do cron toda hora.
     */
    protected function runOnce(OutputInterface $output, int $limit, bool $verbose): int
    {
        $mode = (string) $this->settings->get('ramon-mail-reply.delivery_mode');

        if ($mode !== 'imap') {
            if ($verbose) {
                $output->writeln(sprintf('<comment>mail-reply:fetch — delivery_mode=\'%s\' (skipping, not in IMAP mode)</comment>', $mode));
            }
            return Command::SUCCESS;
        }

        if (! $this->isImapConfigured()) {
            if ($verbose) {
                $output->writeln('<comment>mail-reply:fetch — IMAP not configured (skipping silently)</comment>');
            }
            return Command::SUCCESS;
        }

        try {
            $result = $this->fetcher->fetch($limit);
        } catch (Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '[%s] mail-reply:fetch — processed=%d failed=%d skipped=%d',
            date('H:i:s'),
            $result['processed'],
            $result['failed'],
            $result['skipped'],
        ));

        return Command::SUCCESS;
    }

    protected function isImapConfigured(): bool
    {
        foreach (['imap_host', 'imap_username', 'imap_password'] as $key) {
            if (trim((string) $this->settings->get('ramon-mail-reply.'.$key)) === '') {
                return false;
            }
        }

        return true;
    }
}
