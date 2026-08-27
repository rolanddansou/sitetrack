<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\DTO\AlertRuleInputDto;
use App\Domain\DTO\CheckResultDto;
use App\Domain\DTO\MonitorInputDto;
use App\Domain\Entity\AlertRule;
use App\Domain\Entity\Monitor;
use App\Domain\Entity\Workspace;
use App\Domain\Repository\AlertRuleRepositoryInterface;
use App\Domain\Repository\CheckResultRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Repository\SmtpTestRepositoryInterface;
use App\Domain\Repository\WorkspaceRepositoryInterface;
use App\Domain\Service\PasswordEncryptorInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DashboardController extends AbstractController
{
    public function __construct(
        private MonitorRepositoryInterface $monitorRepository,
        private CheckResultRepositoryInterface $checkResultRepository,
        private SmtpTestRepositoryInterface $smtpTestRepository,
        private AlertRuleRepositoryInterface $alertRuleRepository,
        private WorkspaceRepositoryInterface $workspaceRepository,
        private PasswordEncryptorInterface $passwordEncryptor,
        private ValidatorInterface $validator,
        private Connection $connection
    ) {}

    #[Route('/workspace/{workspacePublicId}/availability', name: 'workspace_availability_index')]
    public function availability(Workspace $workspace): Response
    {
        $monitors = $this->monitorRepository->findActiveMonitorsByWorkspace($workspace->getId());
        $monitorData = [];

        foreach ($monitors as $monitor) {
            $recent = $this->checkResultRepository->findRecentResults($monitor->getId() ?? 0, 1);
            $lastCheck = $recent[0] ?? null;

            $smtpRecent = [];
            if ($monitor->getType() === 'smtp') {
                $smtpRecent = $this->connection->createQueryBuilder()
                    ->select('*')
                    ->from('smtp_tests')
                    ->where('monitor_id = :monitorId')
                    ->setParameter('monitorId', $monitor->getId())
                    ->orderBy('sent_at', 'DESC')
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAllAssociative();
            }
            $lastSmtp = $smtpRecent[0] ?? null;

            $monitorData[] = [
                'entity' => $monitor,
                'lastCheck' => $lastCheck,
                'lastSmtp' => $lastSmtp,
            ];
        }

        return $this->render('dashboard/availability.html.twig', [
            'workspace' => $workspace,
            'workspaces' => $this->workspaceRepository->findByTenant($workspace->getTenantId()),
            'monitors' => $monitorData,
        ]);
    }

    #[Route('/workspace/{workspacePublicId}/monitor/new', name: 'workspace_monitor_new', methods: ['GET', 'POST'])]
    public function new(Workspace $workspace, Request $request): Response
    {
        $dto = new MonitorInputDto();
        $errors = [];

        if ($request->isMethod('POST')) {
            $dto->name = (string) $request->request->get('name');
            $dto->type = (string) $request->request->get('type');
            $dto->target = (string) $request->request->get('target');
            $dto->interval = (int) $request->request->get('interval', 5);
            $dto->expectedStatus = $request->request->get('expected_status') !== null && $request->request->get('expected_status') !== '' ? (int) $request->request->get('expected_status') : 200;
            $dto->regexCheck = $request->request->get('regex_check') ? (string) $request->request->get('regex_check') : null;
            $dto->smtpUsername = $request->request->get('smtp_username') ? (string) $request->request->get('smtp_username') : null;
            $dto->smtpPassword = $request->request->get('smtp_password') ? (string) $request->request->get('smtp_password') : null;
            $dto->smtpSecure = $request->request->get('smtp_secure') ? (string) $request->request->get('smtp_secure') : null;

            $validationErrors = $this->validator->validate($dto);
            if (count($validationErrors) > 0) {
                foreach ($validationErrors as $err) {
                    $errors[$err->getPropertyPath()] = $err->getMessage();
                }
            } else {
                $monitor = new Monitor(
                    workspaceId: $workspace->getId(),
                    name: $dto->name,
                    type: $dto->type,
                    target: $dto->target,
                    interval: $dto->interval
                );

                if ($dto->type === 'http') {
                    $monitor->setExpectedStatus($dto->expectedStatus);
                    $monitor->setRegexCheck($dto->regexCheck);
                } elseif ($dto->type === 'smtp') {
                    $monitor->setSmtpUsername($dto->smtpUsername);
                    $monitor->setSmtpSecure($dto->smtpSecure);
                    if ($dto->smtpPassword) {
                        $monitor->setSmtpPasswordEncrypted($this->passwordEncryptor->encrypt($dto->smtpPassword));
                    }
                }

                $this->monitorRepository->save($monitor);
                $this->addFlash('success', 'Monitor created successfully.');
                return $this->redirectToRoute('workspace_availability_index', ['workspacePublicId' => $workspace->getPublicId()]);
            }
        }

        return $this->render('dashboard/new.html.twig', [
            'workspace' => $workspace,
            'workspaces' => $this->workspaceRepository->findByTenant($workspace->getTenantId()),
            'dto' => $dto,
            'errors' => $errors,
        ]);
    }

    #[Route('/workspace/{workspacePublicId}/monitor/{monitorPublicId}', name: 'workspace_monitor_show', methods: ['GET'])]
    public function show(Workspace $workspace, string $monitorPublicId): Response
    {
        $monitor = $this->findMonitorInWorkspace($workspace, $monitorPublicId);

        $id = $monitor->getId();
        $recentChecks = $this->checkResultRepository->findRecentResults($id, 50);
        $rules = $this->alertRuleRepository->findByMonitor($id);

        $smtpTests = [];
        if ($monitor->getType() === 'smtp') {
            $smtpTests = $this->fetchSmtpTests($id, 30);
        }

        $incidents = $this->fetchIncidents($id);

        return $this->render('dashboard/show.html.twig', [
            'workspace' => $workspace,
            'workspaces' => $this->workspaceRepository->findByTenant($workspace->getTenantId()),
            'monitor' => $monitor,
            'recentChecks' => $recentChecks,
            'smtpTests' => $smtpTests,
            'rules' => $rules,
            'incidents' => $incidents,
        ]);
    }

    /**
     * JSON polling endpoint for the monitor detail page — keeps the status
     * badge, chart, check-history table, and incident log all refreshed
     * together from the same snapshot so they never disagree with each
     * other (e.g. a badge showing a check the table below doesn't have yet).
     */
    #[Route('/workspace/{workspacePublicId}/monitor/{monitorPublicId}/live', name: 'workspace_monitor_live', methods: ['GET'])]
    public function live(Workspace $workspace, string $monitorPublicId): JsonResponse
    {
        $monitor = $this->findMonitorInWorkspace($workspace, $monitorPublicId);

        return new JsonResponse($this->buildMonitorLivePayload($monitor));
    }

    #[Route('/workspace/{workspacePublicId}/monitor/{monitorPublicId}/delete', name: 'workspace_monitor_delete', methods: ['POST'])]
    public function delete(Workspace $workspace, string $monitorPublicId): RedirectResponse
    {
        $monitor = $this->monitorRepository->findByPublicId($monitorPublicId);
        if ($monitor !== null && $monitor->getWorkspaceId() === $workspace->getId()) {
            $this->monitorRepository->delete($monitor);
            $this->addFlash('success', 'Monitor deleted successfully.');
        }
        return $this->redirectToRoute('workspace_availability_index', ['workspacePublicId' => $workspace->getPublicId()]);
    }

    #[Route('/workspace/{workspacePublicId}/monitor/{monitorPublicId}/rule/new', name: 'workspace_rule_new', methods: ['POST'])]
    public function newRule(Workspace $workspace, string $monitorPublicId, Request $request): RedirectResponse
    {
        $monitor = $this->findMonitorInWorkspace($workspace, $monitorPublicId);

        $dto = new AlertRuleInputDto();
        $dto->conditionType = (string) $request->request->get('condition_type');
        $dto->threshold = (int) $request->request->get('threshold', 1);
        $dto->channel = (string) $request->request->get('channel');
        $dto->recipient = (string) $request->request->get('recipient');
        $dto->cooldownMinutes = (int) $request->request->get('cooldown_minutes', 60);

        $validationErrors = $this->validator->validate($dto);
        if (count($validationErrors) > 0) {
            $this->addFlash('error', 'Invalid alert rule configuration.');
        } else {
            $rule = new AlertRule(
                monitorId: $monitor->getId(),
                conditionType: $dto->conditionType,
                threshold: $dto->threshold,
                channel: $dto->channel,
                recipient: $dto->recipient,
                cooldownMinutes: $dto->cooldownMinutes
            );
            $this->alertRuleRepository->save($rule);
            $this->addFlash('success', 'Alert rule added.');
        }

        return $this->redirectToRoute('workspace_monitor_show', ['workspacePublicId' => $workspace->getPublicId(), 'monitorPublicId' => $monitorPublicId]);
    }

    /** @return array<string, mixed> */
    private function buildMonitorLivePayload(Monitor $monitor): array
    {
        $id = $monitor->getId();
        $incidents = array_map($this->mapIncidentRow(...), $this->fetchIncidents($id));

        if ($monitor->getType() === 'smtp') {
            $smtpTests = $this->fetchSmtpTests($id, 30);

            return [
                'monitorType' => 'smtp',
                'status' => $this->buildSmtpStatus($smtpTests[0] ?? null),
                'chart' => $this->buildSmtpChart($smtpTests),
                'smtpTests' => array_map($this->mapSmtpTestRow(...), $smtpTests),
                'incidents' => $incidents,
            ];
        }

        $checks = array_map(CheckResultDto::fromEntity(...), $this->checkResultRepository->findRecentResults($id, 50));

        return [
            'monitorType' => 'http',
            'status' => $this->buildHttpStatus($checks[0] ?? null),
            'chart' => $this->buildHttpChart($checks),
            'checks' => array_map($this->mapCheckDto(...), $checks),
            'incidents' => $incidents,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchSmtpTests(int $monitorId, int $limit): array
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('smtp_tests')
            ->where('monitor_id = :monitorId')
            ->setParameter('monitorId', $monitorId)
            ->orderBy('sent_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchIncidents(int $monitorId): array
    {
        return $this->connection->createQueryBuilder()
            ->select('e.*, r.condition_type, r.channel')
            ->from('alert_events', 'e')
            ->join('e', 'alert_rules', 'r', 'e.rule_id = r.id')
            ->where('r.monitor_id = :monitorId')
            ->setParameter('monitorId', $monitorId)
            ->orderBy('e.triggered_at', 'DESC')
            ->setMaxResults(20)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /** @return array{state: string, label: string, meta: ?string, errorMessage: ?string} */
    private function buildHttpStatus(?CheckResultDto $lastCheck): array
    {
        if ($lastCheck === null) {
            return ['state' => 'pending', 'label' => 'No pings recorded yet', 'meta' => null, 'errorMessage' => null];
        }

        if ($lastCheck->isSuccess()) {
            return [
                'state' => 'up',
                'label' => 'Online',
                'meta' => sprintf('(Checked at %s)', $lastCheck->checkedAt->format('H:i:s')),
                'errorMessage' => null,
            ];
        }

        return ['state' => 'down', 'label' => 'Offline', 'meta' => null, 'errorMessage' => $lastCheck->errorMessage];
    }

    /**
     * @param array<string, mixed>|null $lastSmtp
     * @return array{state: string, label: string, meta: ?string, errorMessage: ?string}
     */
    private function buildSmtpStatus(?array $lastSmtp): array
    {
        if ($lastSmtp === null) {
            return ['state' => 'pending', 'label' => 'No test mail sent yet', 'meta' => null, 'errorMessage' => null];
        }

        if ($lastSmtp['status'] === 'delivered') {
            $receivedAt = $lastSmtp['received_at'] !== null
                ? (new \DateTimeImmutable((string) $lastSmtp['received_at']))->format('H:i:s')
                : null;

            return [
                'state' => 'up',
                'label' => 'Operational',
                'meta' => sprintf('(Delivered in %ss at %s)', $lastSmtp['delivery_time_seconds'], $receivedAt),
                'errorMessage' => null,
            ];
        }

        if ($lastSmtp['status'] === 'sent') {
            return ['state' => 'pending', 'label' => 'Awaiting Delivery Receipt...', 'meta' => null, 'errorMessage' => null];
        }

        return ['state' => 'down', 'label' => 'Failed / Timed Out', 'meta' => null, 'errorMessage' => $lastSmtp['error_message'] ?? null];
    }

    /**
     * @param CheckResultDto[] $checks
     * @return array<int, array{state: string, heightPercent: int, title: string}>
     */
    private function buildHttpChart(array $checks): array
    {
        if ($checks === []) {
            return [];
        }

        $maxVal = 1;
        foreach ($checks as $c) {
            $maxVal = max($maxVal, $c->responseTimeMs);
        }

        $bars = [];
        foreach (array_reverse(array_slice($checks, 0, 30)) as $c) {
            $bars[] = [
                'state' => $c->isSuccess() ? 'up' : 'down',
                'heightPercent' => (int) round($c->responseTimeMs / $maxVal * 100),
                'title' => sprintf('%dms at %s', $c->responseTimeMs, $c->checkedAt->format('H:i:s')),
            ];
        }

        return $bars;
    }

    /**
     * @param array<int, array<string, mixed>> $smtpTests
     * @return array<int, array{state: string, heightPercent: int, title: string}>
     */
    private function buildSmtpChart(array $smtpTests): array
    {
        if ($smtpTests === []) {
            return [];
        }

        $maxVal = 1;
        foreach ($smtpTests as $t) {
            $maxVal = max($maxVal, (int) $t['delivery_time_seconds']);
        }

        $bars = [];
        foreach (array_reverse(array_slice($smtpTests, 0, 30)) as $t) {
            $deliveryTime = (int) ($t['delivery_time_seconds'] ?? 0);
            $pct = $deliveryTime > 0 ? (int) round($deliveryTime / $maxVal * 100) : 0;
            $state = $t['status'] === 'delivered' ? 'up' : ($t['status'] === 'sent' ? 'pending' : 'down');
            $title = $t['status'] === 'delivered' ? sprintf('%ss', $deliveryTime) : (string) $t['status'];

            $bars[] = [
                'state' => $state,
                'heightPercent' => $pct > 0 ? $pct : 5,
                'title' => sprintf('%s at %s', $title, $t['sent_at']),
            ];
        }

        return $bars;
    }

    /** @return array{checkedAt: string, status: string, responseTimeMs: int, errorMessage: ?string} */
    private function mapCheckDto(CheckResultDto $check): array
    {
        return [
            'checkedAt' => $check->checkedAt->format('Y-m-d H:i:s'),
            'status' => $check->status,
            'responseTimeMs' => $check->responseTimeMs,
            'errorMessage' => $check->errorMessage,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapSmtpTestRow(array $row): array
    {
        return [
            'sentAt' => $row['sent_at'],
            'receivedAt' => $row['received_at'],
            'deliveryTimeSeconds' => $row['delivery_time_seconds'] !== null ? (int) $row['delivery_time_seconds'] : null,
            'status' => $row['status'],
            'spfPassed' => $row['spf_passed'] === null ? null : (bool) $row['spf_passed'],
            'dkimPassed' => $row['dkim_passed'] === null ? null : (bool) $row['dkim_passed'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapIncidentRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'conditionType' => $row['condition_type'],
            'triggeredAt' => $row['triggered_at'],
            'notified' => (bool) $row['notified'],
            'status' => $row['status'],
            'resolvedAt' => $row['resolved_at'],
        ];
    }

    /**
     * A monitor that exists but belongs to a different workspace under the
     * same (or another) tenant is treated as not found rather than
     * forbidden — WorkspaceValueResolver already guarantees the caller has
     * access to $workspace itself, so a mismatched monitor is indistinguishable
     * from "doesn't exist" here, and 404 avoids confirming it exists elsewhere.
     */
    private function findMonitorInWorkspace(Workspace $workspace, string $monitorPublicId): Monitor
    {
        $monitor = $this->monitorRepository->findByPublicId($monitorPublicId);
        if ($monitor === null || $monitor->getWorkspaceId() !== $workspace->getId()) {
            throw $this->createNotFoundException('Monitor not found');
        }

        return $monitor;
    }
}
