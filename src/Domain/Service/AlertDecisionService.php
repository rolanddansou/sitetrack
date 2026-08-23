<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\DTO\AlertDecisionDto;
use App\Domain\DTO\AlertRuleDto;
use App\Domain\DTO\AlertEventDto;

class AlertDecisionService implements AlertDecisionServiceInterface
{
    /**
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
    ): array {
        $decisions = [];

        foreach ($rules as $rule) {
            $ruleId = $rule->id;
            if ($ruleId === null) {
                continue;
            }

            $activeAlert = $activeAlerts[$ruleId] ?? null;
            $lastTriggered = $lastTriggeredAlerts[$ruleId] ?? null;

            if ($isCurrentFailure) {
                // Determine if we match condition thresholds
                $thresholdMet = false;
                if ($rule->conditionType === 'latency_threshold') {
                    $thresholdMet = ($latencyMs >= $rule->threshold);
                } else {
                    // For down_count and smtp_fail_count, we check consecutive failures
                    $thresholdMet = ($consecutiveFailures >= $rule->threshold);
                }

                if ($thresholdMet) {
                    if ($activeAlert === null) {
                        // First trigger
                        $decisions[] = new AlertDecisionDto($rule, 'trigger', true);
                    } else {
                        // Already active alert. We check cooldown.
                        if ($lastTriggered !== null) {
                            $diffSeconds = $now->getTimestamp() - $lastTriggered->triggeredAt->getTimestamp();
                            $diffMinutes = (int) ($diffSeconds / 60);

                            if ($diffMinutes >= $rule->cooldownMinutes) {
                                // Cooldown passed, send reminder!
                                $decisions[] = new AlertDecisionDto($rule, 'trigger', true);
                            } else {
                                // Under cooldown, no new notification
                                $decisions[] = new AlertDecisionDto($rule, 'none', false);
                            }
                        } else {
                            $decisions[] = new AlertDecisionDto($rule, 'trigger', true);
                        }
                    }
                } else {
                    $decisions[] = new AlertDecisionDto($rule, 'none', false);
                }
            } else {
                // Currently succeeding
                if ($activeAlert !== null) {
                    // Resolve active alert
                    $decisions[] = new AlertDecisionDto($rule, 'resolve', true);
                } else {
                    $decisions[] = new AlertDecisionDto($rule, 'none', false);
                }
            }
        }

        return $decisions;
    }
}
