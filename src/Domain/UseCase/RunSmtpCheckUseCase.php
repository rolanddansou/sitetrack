<?php

declare(strict_types=1);

namespace App\Domain\UseCase;

use App\Domain\DTO\AlertEventDto;
use App\Domain\DTO\AlertRuleDto;
use App\Domain\DTO\MonitorDto;
use App\Domain\Entity\AlertEvent;
use App\Domain\Entity\SmtpTest;
use App\Domain\Repository\AlertEventRepositoryInterface;
use App\Domain\Repository\AlertRuleRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Repository\SmtpTestRepositoryInterface;
use App\Domain\Service\AlertDecisionServiceInterface;
use App\Domain\Service\NotificationSenderInterface;
use App\Domain\Service\SmtpTesterInterface;

class RunSmtpCheckUseCase
{
    public function __construct(
        private MonitorRepositoryInterface $monitorRepository,
        private SmtpTestRepositoryInterface $smtpTestRepository,
        private AlertRuleRepositoryInterface $alertRuleRepository,
        private AlertEventRepositoryInterface $alertEventRepository,
        private SmtpTesterInterface $smtpTester,
        private AlertDecisionServiceInterface $alertDecisionService,
        private NotificationSenderInterface $notificationSender
    ) {}

    public function execute(int $monitorId, string $token, \DateTimeImmutable $now): void
    {
        $monitor = $this->monitorRepository->find($monitorId);
        if ($monitor === null || $monitor->getType() !== 'smtp') {
            return;
        }

        $monitorDto = MonitorDto::fromEntity($monitor);

        // 1. Create SmtpTest record
        $smtpTest = new SmtpTest($token, $monitorId, 'sent');
        $smtpTest->setSentAt($now);
        $this->smtpTestRepository->save($smtpTest);

        // 2. Dispatch the outbound email
        try {
            $this->smtpTester->sendTestMail($monitorDto, $token);
        } catch (\Throwable $e) {
            $smtpTest->setStatus('failed');
            $smtpTest->setErrorMessage($e->getMessage());
            $this->smtpTestRepository->save($smtpTest);

            // Handle direct SMTP failure alerts (SMTP host down, auth failure)
            $this->triggerDirectFailureAlerts($monitor, $now, $e->getMessage());
        }
    }

    private function triggerDirectFailureAlerts(object $monitor, \DateTimeImmutable $now, string $errorMessage): void
    {
        $monitorId = $monitor->getId();
        if ($monitorId === null) {
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

        // Evaluate alerting: assume 1 failure (as direct SMTP failure is an instant blocker)
        $decisions = $this->alertDecisionService->evaluate(
            $ruleDtos,
            $activeAlerts,
            $lastTriggeredAlerts,
            true, // isCurrentFailure
            1, // consecutiveFailures
            0, // latency
            now: $now
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
                    $msg = sprintf("SMTP Monitor '%s' failed to send mail. Error: %s",
                        $monitor->getName(),
                        $errorMessage
                    );
                    $this->notificationSender->sendAlert($decision->rule, $monitorDto, $msg);
                }
            }
        }
    }
}
