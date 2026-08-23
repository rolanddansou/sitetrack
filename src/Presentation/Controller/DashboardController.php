<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\DTO\AlertRuleInputDto;
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
            $smtpTests = $this->connection->createQueryBuilder()
                ->select('*')
                ->from('smtp_tests')
                ->where('monitor_id = :monitorId')
                ->setParameter('monitorId', $id)
                ->orderBy('sent_at', 'DESC')
                ->setMaxResults(30)
                ->executeQuery()
                ->fetchAllAssociative();
        }

        $incidents = $this->connection->createQueryBuilder()
            ->select('e.*, r.condition_type, r.channel')
            ->from('alert_events', 'e')
            ->join('e', 'alert_rules', 'r', 'e.rule_id = r.id')
            ->where('r.monitor_id = :monitorId')
            ->setParameter('monitorId', $id)
            ->orderBy('e.triggered_at', 'DESC')
            ->setMaxResults(20)
            ->executeQuery()
            ->fetchAllAssociative();

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
