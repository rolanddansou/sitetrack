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
 * and Doctrine closes the EntityManager, app:poll-imap/app:dispatch-checks
 * (also Doctrine-backed) would then fail in that same broken process.
 * Separate processes keep them fully isolated, matching how they'd run as
 * independent crontab lines.
 *
 * `app:dispatch-checks` is invoked directly here rather than relying on
 * symfony/scheduler's RecurringMessage (formerly App\Schedule, now removed):
 * that mechanism depends on the "scheduler_default" Messenger transport
 * correctly producing a due DispatchChecksMessage on every `messenger:consume
 * scheduler_default` poll — which silently never fired in production
 * (monitors stuck "Pending", messenger_messages staying empty, no error
 * anywhere to diagnose). Calling the command straight from a cron-driven
 * process is simpler to reason about and verify on shared hosting.
 * `messenger:consume` now only needs the plain `async` transport, since
 * dispatch-checks itself performs the "which monitors are due" work
 * synchronously and just pushes UptimeCheckMessage/SmtpCheckMessage onto it.
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

        $schedule->addProcess("{$php} {$console} app:dispatch-checks --env={$env}")
            ->description('Trouve les monitors dus et dispatche les vérifications (HTTP/SMTP)')
            ->everyMinute()
            ->withoutOverlapping()
        ;

        $schedule->addProcess("{$php} {$console} messenger:consume async --time-limit=55 --env={$env}")
            ->description('Traite les messages async (checks + analytics)')
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
