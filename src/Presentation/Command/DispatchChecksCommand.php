<?php

declare(strict_types=1);

namespace App\Presentation\Command;

use App\Infrastructure\Scheduler\DispatchDueChecksService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:dispatch-checks',
    description: 'Finds due monitors and triggers pings or SMTP test deliveries.'
)]
class DispatchChecksCommand extends Command
{
    public function __construct(private DispatchDueChecksService $dispatchDueChecks)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $output->writeln(sprintf('<info>[%s] Starting checks dispatcher...</info>', $now->format('Y-m-d H:i:s')));

        foreach ($this->dispatchDueChecks->dispatchDueChecks($now) as $line) {
            $output->writeln($line);
        }

        $output->writeln('<info>Finished checks dispatch.</info>');
        return Command::SUCCESS;
    }
}
