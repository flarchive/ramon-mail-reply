<?php

namespace Ramon\MailReply\Console;

use Ramon\MailReply\Inbound\InboundProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Reads a raw RFC 822 message from STDIN (or --file=) and dispatches it to
 * the inbound processor. Intended for MTA aliases like:
 *
 *     reply: "|/path/to/flarum php flarum mail-reply:process"
 */
#[AsCommand(name: 'mail-reply:process', description: 'Process an RFC 822 message from stdin as an inbound reply.')]
class ProcessInboundEmailCommand extends Command
{
    public function __construct(
        protected InboundProcessor $processor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to an RFC 822 file to read instead of stdin.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $this->readRaw((string) $input->getOption('file'));

        if ($raw === '') {
            $output->writeln('<error>No input received.</error>');

            return Command::FAILURE;
        }

        try {
            $payload = $this->parseRfc822($raw);
            $post = $this->processor->process($payload);
        } catch (Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $output->writeln("Created post #{$post->id} in discussion #{$post->discussion_id}.");

        return Command::SUCCESS;
    }

    protected function readRaw(string $file): string
    {
        if ($file !== '') {
            return (string) file_get_contents($file);
        }

        $stdin = fopen('php://stdin', 'r');

        if ($stdin === false) {
            return '';
        }

        $raw = stream_get_contents($stdin);
        fclose($stdin);

        return (string) $raw;
    }

    /**
     * Minimal RFC 822 splitter. We only need To/From/Subject and the body —
     * deep MIME handling is left to inbound mail providers.
     *
     * @return array{to: array<int,string>, from: string, subject: string, body_text: string, body_html: string}
     */
    protected function parseRfc822(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$headerBlock, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');

        $headers = [];
        $current = null;

        foreach (explode("\n", $headerBlock) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $headers[$current] .= ' '.trim($line);
                continue;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $current = strtolower(trim($name));
            $headers[$current] = trim($value);
        }

        return [
            'to' => isset($headers['to']) ? array_map('trim', explode(',', $headers['to'])) : [],
            'from' => $headers['from'] ?? '',
            'subject' => $headers['subject'] ?? '',
            'body_text' => $body,
            'body_html' => '',
        ];
    }
}
