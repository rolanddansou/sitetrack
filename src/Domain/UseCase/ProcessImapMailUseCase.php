<?php

declare(strict_types=1);

namespace App\Domain\UseCase;

use App\Domain\DTO\AlertEventDto;
use App\Domain\DTO\AlertRuleDto;
use App\Domain\DTO\MonitorDto;
use App\Domain\Repository\AlertEventRepositoryInterface;
use App\Domain\Repository\AlertRuleRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Repository\SmtpTestRepositoryInterface;
use App\Domain\Service\AlertDecisionServiceInterface;
use App\Domain\Service\ImapReaderInterface;
use App\Domain\Service\NotificationSenderInterface;

class ProcessImapMailUseCase
{
    public function __construct(
        private ImapReaderInterface $imapReader,
        private SmtpTestRepositoryInterface $smtpTestRepository,
        private MonitorRepositoryInterface $monitorRepository,
        private AlertRuleRepositoryInterface $alertRuleRepository,
        private AlertEventRepositoryInterface $alertEventRepository,
        private AlertDecisionServiceInterface $alertDecisionService,
        private NotificationSenderInterface $notificationSender
    ) {}

    public function execute(\DateTimeImmutable $now): void
    {
        // Fetch new emails and clean inbox
        $inboundEmails = $this->imapReader->fetchAndCleanTestEmails();

        foreach ($inboundEmails as $emailDto) {
            $smtpTest = $this->smtpTestRepository->find($emailDto->token);
            if ($smtpTest === null || $smtpTest->getStatus() !== 'sent') {
                continue;
            }

            // Compute metrics
            $sentTimestamp = $smtpTest->getSentAt()->getTimestamp();
            $receivedTimestamp = $emailDto->receivedAt->getTimestamp();
            $deliveryTimeSeconds = max(0, $receivedTimestamp - $sentTimestamp);

            // Update SmtpTest
            $smtpTest->setStatus('delivered');
            $smtpTest->setReceivedAt($emailDto->receivedAt);
            $smtpTest->setDeliveryTimeSeconds($deliveryTimeSeconds);
            $smtpTest->setSpfPassed($emailDto->spfPassed);
            $smtpTest->setDkimPassed($emailDto->dkimPassed);
            $smtpTest->setDmarcPassed($emailDto->dmarcPassed);
            $smtpTest->setSpamScore($emailDto->spamScore);

            $this->smtpTestRepository->save($smtpTest);

            // Resolve any active alerts for this monitor since SMTP is verified!
            $this->resolveActiveAlertsForMonitor($smtpTest->getMonitorId(), $now);
        }
    }

    private function resolveActiveAlertsForMonitor(int $monitorId, \DateTimeImmutable $now): void
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

        foreach ($rules as $rule) {
            $ruleId = $rule->getId();
            if ($ruleId === null) {
                continue;
            }

            $active = $this->alertEventRepository->findActiveAlert($ruleId);
            if ($active !== null) {
                // Evaluate with isCurrentFailure = false to resolve
                $ruleDto = AlertRuleDto::fromEntity($rule);
                $activeDto = AlertEventDto::fromEntity($active);

                $decisions = $this->alertDecisionService->evaluate(
                    [$ruleDto],
                    [$ruleId => $activeDto],
                    [$ruleId => $activeDto],
                    false, // isCurrentFailure
                    0, // consecutiveFailures
                    0, // latency
                    $now
                );

                foreach ($decisions as $decision) {
                    if ($decision->action === 'resolve') {
                        $active->setStatus('resolved');
                        $active->setResolvedAt($now);
                        $this->alertEventRepository->save($active);

                        if ($decision->shouldNotify) {
                            $msg = sprintf("SMTP Monitor '%s' has RECOVERED. Test mail was delivered.", $monitor->getName());
                            $this->notificationSender->sendRecovery($decision->rule, $monitorDto, $msg);
                        }
                    }
                }
            }
        }
    }
}
