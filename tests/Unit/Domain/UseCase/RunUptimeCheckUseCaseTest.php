<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\UseCase;

use App\Domain\DTO\AlertDecisionDto;
use App\Domain\DTO\AlertRuleDto;
use App\Domain\DTO\MonitorDto;
use App\Domain\Entity\AlertEvent;
use App\Domain\Entity\AlertRule;
use App\Domain\Entity\CheckResult;
use App\Domain\Entity\Monitor;
use App\Domain\Repository\AlertEventRepositoryInterface;
use App\Domain\Repository\AlertRuleRepositoryInterface;
use App\Domain\Repository\CheckResultRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Service\AlertDecisionServiceInterface;
use App\Domain\Service\NotificationSenderInterface;
use App\Domain\Service\PingServiceInterface;
use App\Domain\UseCase\RunUptimeCheckUseCase;
use PHPUnit\Framework\TestCase;

class RunUptimeCheckUseCaseTest extends TestCase
{
    public function testExecuteSuccessfulCheckAndTriggerAlert(): void
    {
        $now = new \DateTimeImmutable('2026-08-22 12:00:00');
        $monitor = (new Monitor(1, 'Test Service', 'http', 'https://test.com', 5))->setId(10);
        $checkResult = new CheckResult(10, 'down', 120, $now, 'Connection Timeout');

        // Create Mocks
        $monitorRepo = $this->createMock(MonitorRepositoryInterface::class);
        $monitorRepo->method('find')->with(10)->willReturn($monitor);

        $checkResultRepo = $this->createMock(CheckResultRepositoryInterface::class);
        $checkResultRepo->expects($this->once())->method('save')->with($checkResult);
        $checkResultRepo->method('findConsecutiveFailures')->with(10)->willReturn(3);

        $rule = (new AlertRule(10, 'down_count', 3, 'email', 'alert@test.com'))->setId(1);
        $ruleRepo = $this->createMock(AlertRuleRepositoryInterface::class);
        $ruleRepo->method('findByMonitor')->with(10)->willReturn([$rule]);

        $alertEventRepo = $this->createMock(AlertEventRepositoryInterface::class);
        $alertEventRepo->method('findActiveAlert')->with(1)->willReturn(null);
        $alertEventRepo->method('findLastTriggeredAlert')->with(1)->willReturn(null);

        $alertEventRepo->expects($this->once())->method('save')->with($this->callback(function (AlertEvent $event) {
            return $event->getRuleId() === 1 && $event->getStatus() === 'triggered';
        }));

        $pingService = $this->createMock(PingServiceInterface::class);
        $pingService->method('ping')->willReturn($checkResult);

        $decision = new AlertDecisionDto(AlertRuleDto::fromEntity($rule), 'trigger', true);
        $decisionService = $this->createMock(AlertDecisionServiceInterface::class);
        $decisionService->method('evaluate')->willReturn([$decision]);

        $notifier = $this->createMock(NotificationSenderInterface::class);
        $notifier->expects($this->once())->method('sendAlert');

        $useCase = new RunUptimeCheckUseCase(
            $monitorRepo,
            $checkResultRepo,
            $ruleRepo,
            $alertEventRepo,
            $pingService,
            $decisionService,
            $notifier
        );

        $useCase->execute(10, $now);
    }
}
