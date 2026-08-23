<?php

declare(strict_types=1);

namespace App\Domain\UseCase;

use App\Domain\DTO\AlertEventDto;
use App\Domain\DTO\AlertRuleDto;
use App\Domain\DTO\MonitorDto;
use App\Domain\Entity\AlertEvent;
use App\Domain\Repository\AlertEventRepositoryInterface;
use App\Domain\Repository\AlertRuleRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Repository\SmtpTestRepositoryInterface;
use App\Domain\Service\AlertDecisionServiceInterface;
use App\Domain\Service\NotificationSenderInterface;

class SweepSmtpTestsUseCase
{
    public function __construct(
        private SmtpTestRepositoryInterface $smtpTestRepository,
        private MonitorRepositoryInterface $monitorRepository,
        private AlertRuleRepositoryInterface $alertRuleRepository,
        private AlertEventRepositoryInterface $alertEventRepository,
        private AlertDecisionServiceInterface $alertDecisionService,
        private NotificationSenderInterface $notificationSender
    ) {}

    public function execute(\DateTimeImmutable $now, int $timeoutMinutes = 15): void
    {
        $before = $now->modify(sprintf('-%d minutes', $timeoutMinutes));
        $expiredTests = $this->smtpTestRepository->findExpiredSentTests($before);

        foreach ($expiredTests as $test) {
            $test->setStatus('timeout');
            $test->setErrorMessage(sprintf('Email did not arrive within %d minutes.', $timeoutMinutes));
            $this->smtpTestRepository->save($test);

            // Trigger failure alerting for this monitor
            $this->triggerFailureAlerts($test->getMonitorId(), $now, $timeoutMinutes);
        }
    }

    private function triggerFailureAlerts(int $monitorId, \DateTimeImmutable $now, int $timeoutMinutes): void
    {
        $monitor = $this->monitorRepository->find($monitorId);
        if ($monitor === null) {
            return;
        }
        $monitorDto = MonitorDto::fromEntity($monitor);
        $rules = $this->alertRuleRepository->findByMonitor($monitorId);
        if (empty($rules)) {
            return;
        }

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

        // Evaluate alerting: SMTP timed out counts as failure
        $decisions = $this->alertDecisionService->evaluate(
            $ruleDtos,
            $activeAlerts,
            $lastTriggeredAlerts,
            true, // isCurrentFailure
            1, // consecutiveFailures
            0, // latency
            $now
        );

        foreach ($decisions as $decision) {
            if ($decision->action === 'trigger') {
                $activeEntity = null;
                $activeDto = $activeAlerts[$decision->rule->id] ?? null;

                if ($activeDto === null) {
                    $activeEntity = new AlertEvent($decision->rule->id, 'triggered', $now, $decision->shouldNotify);
                } else {
                    $activeEntity = $this->alertEventRepository->findActiveAlert($decision->rule->id);
                    if ($activeEntity !== null) {
                        $activeEntity->setNotified(true);
                    }
                }

                if ($activeEntity !== null) {
                    $this->alertEventRepository->save($activeEntity);
                }

                if ($decision->shouldNotify) {
                    $msg = sprintf("SMTP Monitor '%s' check timed out. Email failed to arrive within %d minutes.",
                        $monitor->getName(),
                        $timeoutMinutes
                    );
                    $this->notificationSender->sendAlert($decision->rule, $monitorDto, $msg);
                }
            }
        }
    }
}
