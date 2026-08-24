<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Zenstruck\ScheduleBundle\Schedule;
use Zenstruck\ScheduleBundle\Schedule\ScheduleBuilder;

/**
 * Centralizes the recurring server-side tasks behind a single cron entry
 * (`schedule:run`, every minute — see .github/workflows/deploy.yml) instead
 * of one raw crontab line per task. Auto-registered — implementing
 * ScheduleBuilder is enough, no service.yaml wiring needed.
 *
 * Tasks run as real OS subprocesses (addProcess()), not in-process
 * (addCommand()): zenstruck's CommandTaskRunner would otherwise run every
 * due task through the same Application/container instance within one
 * schedule:run invocation — if messenger:consume hits a fatal DBAL error
 * and Doctrine closes the EntityManager, app:poll-imap (also Doctrine-backed)
 * would then fail in that same broken process. Separate processes keep the
 * two fully isolated, matching how they'd run as independent crontab lines.
 *
 * Does not replace symfony/scheduler (App\Schedule, #[AsSchedule]): that
 * still owns the internal 1-minute tick that dispatches DispatchChecksMessage
 * on the "scheduler_default" transport. This builder's job is only to keep a
 * bounded `messenger:consume` process alive so that tick (and the resulting
 * async messages) actually get processed, plus the separate IMAP poll.
 */
class AppScheduleBuilder implements ScheduleBuilder
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/bin/console')]
        private string $consolePath,
        #[Autowire('%kernel.environment%')]
        private string $environment
    ) {}

    public function buildSchedule(Schedule $schedule): void
    {
        $php = escapeshellarg(PHP_BINARY);
        $console = escapeshellarg($this->consolePath);
        $env = escapeshellarg($this->environment);

        $schedule->addProcess("{$php} {$console} messenger:consume scheduler_default async --time-limit=55 --env={$env}")
            ->description('Traite le tick du scheduler (checks) et les messages async (checks + analytics)')
            ->everyMinute()
            ->withoutOverlapping()
        ;

        $schedule->addProcess("{$php} {$console} app:poll-imap --env={$env}")
            ->description('Dépouille la boîte IMAP pour les tests de délivrabilité SMTP')
            ->everyMinute()
            ->withoutOverlapping()
        ;
    }
}
