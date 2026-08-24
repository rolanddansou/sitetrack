<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Scheduler;

use App\Infrastructure\Scheduler\AppScheduleBuilder;
use PHPUnit\Framework\TestCase;
use Zenstruck\ScheduleBundle\Schedule;
use Zenstruck\ScheduleBundle\Schedule\Extension\WithoutOverlappingExtension;
use Zenstruck\ScheduleBundle\Schedule\Task\ProcessTask;

class AppScheduleBuilderTest extends TestCase
{
    public function testBuildsTheMessengerConsumeAndImapPollTasksEveryMinuteWithoutOverlapping(): void
    {
        $schedule = new Schedule();
        (new AppScheduleBuilder('/app/bin/console', 'prod'))->buildSchedule($schedule);

        $tasks = $schedule->all();
        $this->assertCount(2, $tasks);

        foreach ($tasks as $task) {
            $this->assertInstanceOf(ProcessTask::class, $task);
            $this->assertSame('* * * * *', (string) $task->getExpression());

            $hasWithoutOverlapping = array_reduce(
                $task->getExtensions(),
                static fn (bool $carry, object $extension): bool => $carry || $extension instanceof WithoutOverlappingExtension,
                false
            );
            $this->assertTrue($hasWithoutOverlapping, 'Each task must be guarded by withoutOverlapping().');
        }

        // Quoting style (single vs double quotes) comes from escapeshellarg(),
        // which is platform-dependent (Windows vs Unix) — assert on content,
        // not on an exact quote character.
        [$messengerTask, $imapTask] = $tasks;
        $messengerCommandLine = $messengerTask->getProcess()->getCommandLine();
        $this->assertStringContainsString('messenger:consume', $messengerCommandLine);
        $this->assertStringContainsString('scheduler_default', $messengerCommandLine);
        $this->assertStringContainsString('--time-limit=55', $messengerCommandLine);
        $this->assertMatchesRegularExpression('/--env=[\'"]prod[\'"]/', $messengerCommandLine);
        $this->assertStringContainsString('/app/bin/console', $messengerCommandLine);

        $imapCommandLine = $imapTask->getProcess()->getCommandLine();
        $this->assertStringContainsString('app:poll-imap', $imapCommandLine);
        $this->assertMatchesRegularExpression('/--env=[\'"]prod[\'"]/', $imapCommandLine);
    }
}
