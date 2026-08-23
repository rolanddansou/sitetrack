<?php

declare(strict_types=1);

namespace App\Presentation\Command;

use App\Domain\UseCase\ProcessImapMailUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:poll-imap',
    description: 'Polls the central verification IMAP mailbox for returned test emails.'
)]
class PollImapCommand extends Command
{
    public function __construct(private ProcessImapMailUseCase $useCase)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run continuously in a loop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $daemon = $input->getOption('daemon');

        if ($daemon) {
            $output->writeln('<info>Starting IMAP polling daemon (Ctrl+C to exit)...</info>');
            while (true) {
                $now = new \DateTimeImmutable();
                $output->writeln(sprintf('[%s] Polling IMAP...', $now->format('Y-m-d H:i:s')));

                try {
                    $this->useCase->execute($now);
                } catch (\Throwable $e) {
                    $output->writeln(sprintf('<error>IMAP polling failed: %s</error>', $e->getMessage()));
                }

                sleep(15);
            }
        } else {
            $now = new \DateTimeImmutable();
            $output->writeln(sprintf('[%s] Polling IMAP once...', $now->format('Y-m-d H:i:s')));
            try {
                $this->useCase->execute($now);
                $output->writeln('<info>IMAP poll completed.</info>');
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>IMAP polling failed: %s</error>', $e->getMessage()));
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
