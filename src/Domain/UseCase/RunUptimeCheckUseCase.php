<?php

declare(strict_types=1);

namespace App\Domain\UseCase;

use App\Domain\DTO\AlertEventDto;
use App\Domain\DTO\AlertRuleDto;
use App\Domain\DTO\MonitorDto;
use App\Domain\Entity\AlertEvent;
use App\Domain\Repository\AlertEventRepositoryInterface;
use App\Domain\Repository\AlertRuleRepositoryInterface;
use App\Domain\Repository\CheckResultRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Service\AlertDecisionServiceInterface;
use App\Domain\Service\NotificationSenderInterface;
use App\Domain\Service\PingServiceInterface;

class RunUptimeCheckUseCase
{
    public function __construct(
        private MonitorRepositoryInterface $monitorRepository,
        private CheckResultRepositoryInterface $checkResultRepository,
        private AlertRuleRepositoryInterface $alertRuleRepository,
        private AlertEventRepositoryInterface $alertEventRepository,
        private PingServiceInterface $pingService,
        private AlertDecisionServiceInterface $alertDecisionService,
        private NotificationSenderInterface $notificationSender
    ) {}

    public function execute(int $monitorId, \DateTimeImmutable $now): void
    {
        $monitor = $this->monitorRepository->find($monitorId);
        if ($monitor === null) {
            return;
        }

        $monitorDto = MonitorDto::fromEntity($monitor);

        // 1. Perform HTTP check
        $checkResult = $this->pingService->ping($monitorDto);
        $this->checkResultRepository->save($checkResult);

        // 2. Load rules
        $rules = $this->alertRuleRepository->findByMonitor($monitorId);
        if (empty($rules)) {
            return;
        }

        // 3. Load active alert events & last triggered alert events
        $activeAlerts = [];
        $lastTriggeredAlerts = [];
        $ruleDtos = [];

        foreach ($rules as $rule) {
            $ruleId = $rule->getId();
            if ($ruleId === null) {
                continue;
            }

            $ruleDtos[] = AlertRuleDto::fromEntity($rule);

            $active = $this->alertEventRepository->findActiveAlert($ruleId);
            if ($active !== null) {
                $activeAlerts[$ruleId] = AlertEventDto::fromEntity($active);
            }

            $last = $this->alertEventRepository->findLastTriggeredAlert($ruleId);
            if ($last !== null) {
                $lastTriggeredAlerts[$ruleId] = AlertEventDto::fromEntity($last);
            }
        }

        // Calculate consecutive failures
        $consecutiveFailures = $this->checkResultRepository->findConsecutiveFailures($monitorId);

        // 4. Evaluate alerting decisions
        $decisions = $this->alertDecisionService->evaluate(
            $ruleDtos,
            $activeAlerts,
            $lastTriggeredAlerts,
            $checkResult->isFailure(),
            $consecutiveFailures,
            $checkResult->getResponseTimeMs(),
            $now
        );

        // 5. Apply decisions
        foreach ($decisions as $decision) {
            $rule = null;
            foreach ($rules as $r) {
                if ($r->getId() === $decision->rule->id) {
                    $rule = $r;
                    break;
                }
            }
            if ($rule === null) {
                continue;
            }

            if ($decision->action === 'trigger') {
                $activeEntity = null;
                $activeDto = $activeAlerts[$decision->rule->id] ?? null;

                if ($activeDto === null) {
                    // Create new active alert event
                    $activeEntity = new AlertEvent($decision->rule->id, 'triggered', $now, $decision->shouldNotify);
                } else {
                    // Update existing active alert to log last triggered/notified
                    $activeEntity = $this->alertEventRepository->findActiveAlert($decision->rule->id);
                    if ($activeEntity !== null) {
                        $activeEntity->setNotified(true);
                    }
                }

                if ($activeEntity !== null) {
                    $this->alertEventRepository->save($activeEntity);
                }

                if ($decision->shouldNotify) {
                    $msg = sprintf("Monitor '%s' is DOWN. Status: %s. Response time: %dms. Error: %s",
                        $monitor->getName(),
                        $checkResult->getStatus(),
                        $checkResult->getResponseTimeMs(),
                        $checkResult->getErrorMessage() ?? 'none'
                    );
                    $this->notificationSender->sendAlert($decision->rule, $monitorDto, $msg);
                }

            } elseif ($decision->action === 'resolve') {
                $activeEntity = $this->alertEventRepository->findActiveAlert($decision->rule->id);
                if ($activeEntity !== null) {
                    $activeEntity->setStatus('resolved');
                    $activeEntity->setResolvedAt($now);
                    $this->alertEventRepository->save($activeEntity);
                }

                if ($decision->shouldNotify) {
                    $msg = sprintf("Monitor '%s' has RECOVERED. Status is now UP. Response time: %dms.",
                        $monitor->getName(),
                        $checkResult->getResponseTimeMs()
                    );
                    $this->notificationSender->sendRecovery($decision->rule, $monitorDto, $msg);
                }
            }
        }
    }
}
