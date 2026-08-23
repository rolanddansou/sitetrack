<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\DTO\AlertDecisionDto;
use App\Domain\DTO\AlertRuleDto;
use App\Domain\DTO\AlertEventDto;

interface AlertDecisionServiceInterface
{
    /**
     * Evaluate alert status based on rule, check status, history, and active alerts.
     *
     * @param AlertRuleDto[] $rules
     * @param AlertEventDto[] $activeAlerts Map of rule_id => AlertEventDto (if active)
     * @param AlertEventDto[] $lastTriggeredAlerts Map of rule_id => AlertEventDto (historical last triggered)
     */
    public function evaluate(
        array $rules,
        array $activeAlerts,
        array $lastTriggeredAlerts,
        bool $isCurrentFailure,
        int $consecutiveFailures,
        int $latencyMs,
        \DateTimeImmutable $now
    ): array;
}
