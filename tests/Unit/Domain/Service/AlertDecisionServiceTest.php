<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Service;

use App\Domain\DTO\AlertEventDto;
use App\Domain\DTO\AlertRuleDto;
use App\Domain\Service\AlertDecisionService;
use PHPUnit\Framework\TestCase;

class AlertDecisionServiceTest extends TestCase
{
    private AlertDecisionService $service;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->service = new AlertDecisionService();
        $this->now = new \DateTimeImmutable('2026-08-22 12:00:00');
    }

    public function testFirstTrigger(): void
    {
        $rule = new AlertRuleDto(
            id: 1,
            monitorId: 10,
            conditionType: 'down_count',
            threshold: 3,
            channel: 'email',
            recipient: 'alert@test.com',
            cooldownMinutes: 60
        );

        $decisions = $this->service->evaluate(
            rules: [$rule],
            activeAlerts: [],
            lastTriggeredAlerts: [],
            isCurrentFailure: true,
            consecutiveFailures: 3,
            latencyMs: 150,
            now: $this->now
        );

        $this->assertCount(1, $decisions);
        $this->assertSame(1, $decisions[0]->rule->id);
        $this->assertSame('trigger', $decisions[0]->action);
        $this->assertTrue($decisions[0]->shouldNotify);
    }

    public function testUnderThresholdDoesNotTrigger(): void
    {
        $rule = new AlertRuleDto(
            id: 1,
            monitorId: 10,
            conditionType: 'down_count',
            threshold: 3,
            channel: 'email',
            recipient: 'alert@test.com',
            cooldownMinutes: 60
        );

        $decisions = $this->service->evaluate(
            rules: [$rule],
            activeAlerts: [],
            lastTriggeredAlerts: [],
            isCurrentFailure: true,
            consecutiveFailures: 2,
            latencyMs: 150,
            now: $this->now
        );

        $this->assertCount(1, $decisions);
        $this->assertSame('none', $decisions[0]->action);
        $this->assertFalse($decisions[0]->shouldNotify);
    }

    public function testActiveAlertUnderCooldown(): void
    {
        $rule = new AlertRuleDto(
            id: 1,
            monitorId: 10,
            conditionType: 'down_count',
            threshold: 3,
            channel: 'email',
            recipient: 'alert@test.com',
            cooldownMinutes: 60
        );

        $activeAlert = new AlertEventDto(100, 1, 'triggered', $this->now->modify('-30 minutes'), null, true);
        $lastTriggered = $activeAlert;

        $decisions = $this->service->evaluate(
            rules: [$rule],
            activeAlerts: [1 => $activeAlert],
            lastTriggeredAlerts: [1 => $lastTriggered],
            isCurrentFailure: true,
            consecutiveFailures: 3,
            latencyMs: 150,
            now: $this->now
        );

        $this->assertCount(1, $decisions);
        $this->assertSame('none', $decisions[0]->action);
        $this->assertFalse($decisions[0]->shouldNotify);
    }

    public function testActiveAlertCooldownPassed(): void
    {
        $rule = new AlertRuleDto(
            id: 1,
            monitorId: 10,
            conditionType: 'down_count',
            threshold: 3,
            channel: 'email',
            recipient: 'alert@test.com',
            cooldownMinutes: 60
        );

        $activeAlert = new AlertEventDto(100, 1, 'triggered', $this->now->modify('-61 minutes'), null, true);
        $lastTriggered = $activeAlert;

        $decisions = $this->service->evaluate(
            rules: [$rule],
            activeAlerts: [1 => $activeAlert],
            lastTriggeredAlerts: [1 => $lastTriggered],
            isCurrentFailure: true,
            consecutiveFailures: 3,
            latencyMs: 150,
            now: $this->now
        );

        $this->assertCount(1, $decisions);
        $this->assertSame('trigger', $decisions[0]->action);
        $this->assertTrue($decisions[0]->shouldNotify);
    }

    public function testRecovery(): void
    {
        $rule = new AlertRuleDto(
            id: 1,
            monitorId: 10,
            conditionType: 'down_count',
            threshold: 3,
            channel: 'email',
            recipient: 'alert@test.com',
            cooldownMinutes: 60
        );

        $activeAlert = new AlertEventDto(100, 1, 'triggered', $this->now->modify('-10 minutes'), null, true);

        $decisions = $this->service->evaluate(
            rules: [$rule],
            activeAlerts: [1 => $activeAlert],
            lastTriggeredAlerts: [1 => $activeAlert],
            isCurrentFailure: false,
            consecutiveFailures: 0,
            latencyMs: 50,
            now: $this->now
        );

        $this->assertCount(1, $decisions);
        $this->assertSame('resolve', $decisions[0]->action);
        $this->assertTrue($decisions[0]->shouldNotify);
    }

    public function testLatencyThresholdTrigger(): void
    {
        $rule = new AlertRuleDto(
            id: 1,
            monitorId: 10,
            conditionType: 'latency_threshold',
            threshold: 500, // 500 ms limit
            channel: 'email',
            recipient: 'alert@test.com',
            cooldownMinutes: 60
        );

        $decisions = $this->service->evaluate(
            rules: [$rule],
            activeAlerts: [],
            lastTriggeredAlerts: [],
            isCurrentFailure: true,
            consecutiveFailures: 1,
            latencyMs: 550, // exceeds threshold
            now: $this->now
        );

        $this->assertCount(1, $decisions);
        $this->assertSame('trigger', $decisions[0]->action);
        $this->assertTrue($decisions[0]->shouldNotify);
    }
}
