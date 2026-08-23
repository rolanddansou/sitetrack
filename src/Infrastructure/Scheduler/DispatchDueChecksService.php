<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Domain\Repository\CheckResultRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\UseCase\SweepSmtpTestsUseCase;
use App\Infrastructure\Queue\Message\SmtpCheckMessage;
use App\Infrastructure\Queue\Message\UptimeCheckMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Finds due monitors and dispatches their checks onto the message bus.
 * Shared by app:dispatch-checks (manual/cron trigger) and the scheduler message handler.
 */
class DispatchDueChecksService
{
    public function __construct(
        private MonitorRepositoryInterface $monitorRepository,
        private CheckResultRepositoryInterface $checkResultRepository,
        private SweepSmtpTestsUseCase $sweepSmtpTests,
        private Connection $connection,
        private MessageBusInterface $messageBus
    ) {}

    /**
     * @return string[] Log lines describing what was dispatched, for callers that want to report it.
     */
    public function dispatchDueChecks(\DateTimeImmutable $now): array
    {
        $log = [];

        $this->sweepSmtpTests->execute($now, 15); // 15 mins timeout

        $monitors = $this->monitorRepository->findActiveMonitors();
        $log[] = sprintf('Found %d monitors to evaluate.', count($monitors));

        foreach ($monitors as $monitor) {
            $monitorId = $monitor->getId();
            if ($monitorId === null) {
                continue;
            }

            $isDue = false;

            if ($monitor->getType() === 'http') {
                $recent = $this->checkResultRepository->findRecentResults($monitorId, 1);
                $lastCheck = $recent[0] ?? null;

                if ($lastCheck === null) {
                    $isDue = true;
                } else {
                    $diffMinutes = ($now->getTimestamp() - $lastCheck->getCheckedAt()->getTimestamp()) / 60;
                    if ($diffMinutes >= $monitor->getInterval()) {
                        $isDue = true;
                    }
                }

                if ($isDue) {
                    $log[] = sprintf('Dispatching HTTP check for "%s" (%s)...', $monitor->getName(), $monitor->getTarget());
                    try {
                        $this->messageBus->dispatch(new UptimeCheckMessage($monitorId, $now));
                    } catch (\Throwable $e) {
                        $log[] = sprintf('Failed to dispatch HTTP check for %s: %s', $monitor->getName(), $e->getMessage());
                    }
                }
            } elseif ($monitor->getType() === 'smtp') {
                $lastTest = $this->connection->createQueryBuilder()
                    ->select('sent_at')
                    ->from('smtp_tests')
                    ->where('monitor_id = :monitorId')
                    ->setParameter('monitorId', $monitorId)
                    ->orderBy('sent_at', 'DESC')
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchOne();

                if ($lastTest === false) {
                    $isDue = true;
                } else {
                    $lastSentAt = new \DateTimeImmutable($lastTest);
                    $diffMinutes = ($now->getTimestamp() - $lastSentAt->getTimestamp()) / 60;
                    if ($diffMinutes >= $monitor->getInterval()) {
                        $isDue = true;
                    }
                }

                if ($isDue) {
                    $token = bin2hex(random_bytes(16));
                    $log[] = sprintf('Dispatching SMTP check for "%s" with token %s...', $monitor->getName(), $token);
                    try {
                        $this->messageBus->dispatch(new SmtpCheckMessage($monitorId, $token, $now));
                    } catch (\Throwable $e) {
                        $log[] = sprintf('Failed to dispatch SMTP check for %s: %s', $monitor->getName(), $e->getMessage());
                    }
                }
            }
        }

        return $log;
    }
}
