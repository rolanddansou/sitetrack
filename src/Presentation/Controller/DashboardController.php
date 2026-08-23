<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\DTO\AlertRuleInputDto;
use App\Domain\DTO\MonitorInputDto;
use App\Domain\Entity\AlertRule;
use App\Domain\Entity\Monitor;
use App\Domain\Repository\AlertRuleRepositoryInterface;
use App\Domain\Repository\CheckResultRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Repository\SmtpTestRepositoryInterface;
use App\Domain\Repository\TenantMembershipRepositoryInterface;
use App\Domain\Repository\TenantRepositoryInterface;
use App\Domain\Service\PasswordEncryptorInterface;
use App\Infrastructure\Security\IdentityUser;
use App\Infrastructure\Security\MonitorVoter;
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
        private TenantMembershipRepositoryInterface $tenantMembershipRepository,
        private TenantRepositoryInterface $tenantRepository,
        private PasswordEncryptorInterface $passwordEncryptor,
        private ValidatorInterface $validator,
        private Connection $connection
    ) {}

    private function currentTenantId(): int
    {
        /** @var IdentityUser $user */
        $user = $this->getUser();
        $identityId = $user->getIdentity()->getId();

        $memberships = $this->tenantMembershipRepository->findByIdentityId($identityId ?? 0);
        if (count($memberships) === 0) {
            throw $this->createAccessDeniedException('No tenant membership found for this identity.');
        }

        return $memberships[0]->getTenantId();
    }

    #[Route('/dashboard', name: 'dashboard_index')]
    public function index(): Response
    {
        $monitors = $this->monitorRepository->findActiveMonitorsByTenant($this->currentTenantId());
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

        return $this->render('dashboard/index.html.twig', [
            'monitors' => $monitorData,
        ]);
    }

    #[Route('/monitor/new', name: 'monitor_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
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
                    tenantId: $this->currentTenantId(),
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
                return $this->redirectToRoute('dashboard_index');
            }
        }

        return $this->render('dashboard/new.html.twig', [
            'dto' => $dto,
            'errors' => $errors,
        ]);
    }

    #[Route('/monitor/{publicId}', name: 'monitor_show', methods: ['GET'])]
    public function show(string $publicId): Response
    {
        $monitor = $this->monitorRepository->findByPublicId($publicId);
        if ($monitor === null) {
            throw $this->createNotFoundException('Monitor not found');
        }
        $this->denyAccessUnlessGranted(MonitorVoter::MONITOR_ACCESS, $monitor);

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
            'monitor' => $monitor,
            'recentChecks' => $recentChecks,
            'smtpTests' => $smtpTests,
            'rules' => $rules,
            'incidents' => $incidents,
        ]);
    }

    #[Route('/monitor/{publicId}/delete', name: 'monitor_delete', methods: ['POST'])]
    public function delete(string $publicId): RedirectResponse
    {
        $monitor = $this->monitorRepository->findByPublicId($publicId);
        if ($monitor !== null) {
            $this->denyAccessUnlessGranted(MonitorVoter::MONITOR_ACCESS, $monitor);
            $this->monitorRepository->delete($monitor);
            $this->addFlash('success', 'Monitor deleted successfully.');
        }
        return $this->redirectToRoute('dashboard_index');
    }

    #[Route('/monitor/{publicId}/rule/new', name: 'rule_new', methods: ['POST'])]
    public function newRule(string $publicId, Request $request): RedirectResponse
    {
        $monitor = $this->monitorRepository->findByPublicId($publicId);
        if ($monitor === null) {
            throw $this->createNotFoundException('Monitor not found');
        }
        $this->denyAccessUnlessGranted(MonitorVoter::MONITOR_ACCESS, $monitor);

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

        return $this->redirectToRoute('monitor_show', ['publicId' => $publicId]);
    }

    #[Route('/analytics', name: 'analytics_index', methods: ['GET'])]
    public function analytics(): Response
    {
        $tenant = $this->tenantRepository->find($this->currentTenantId());
        if ($tenant === null) {
            throw $this->createNotFoundException('Tenant not found');
        }
        $siteId = $tenant->getSiteId();

        $uniqueVisitors = (int) $this->connection->createQueryBuilder()
            ->select('COUNT(DISTINCT session_id)')
            ->from('analytics_events')
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId)
            ->executeQuery()
            ->fetchOne();

        $pageviews = (int) $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('analytics_events')
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId)
            ->executeQuery()
            ->fetchOne();

        $topPages = $this->connection->createQueryBuilder()
            ->select('path, COUNT(*) as count')
            ->from('analytics_events')
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId)
            ->groupBy('path')
            ->orderBy('count', 'DESC')
            ->setMaxResults(10)
            ->executeQuery()
            ->fetchAllAssociative();

        $topReferrers = $this->connection->createQueryBuilder()
            ->select('referrer, COUNT(*) as count')
            ->from('analytics_events')
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId)
            ->groupBy('referrer')
            ->orderBy('count', 'DESC')
            ->setMaxResults(10)
            ->executeQuery()
            ->fetchAllAssociative();

        $countries = $this->connection->createQueryBuilder()
            ->select('country, COUNT(*) as count')
            ->from('analytics_events')
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId)
            ->groupBy('country')
            ->orderBy('count', 'DESC')
            ->setMaxResults(10)
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->render('dashboard/analytics.html.twig', [
            'siteId' => $siteId,
            'uniqueVisitors' => $uniqueVisitors,
            'pageviews' => $pageviews,
            'topPages' => $topPages,
            'topReferrers' => $topReferrers,
            'countries' => $countries,
        ]);
    }
}
