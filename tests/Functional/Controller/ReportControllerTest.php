<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Domain\Entity\CheckResult;
use App\Domain\Entity\Identity;
use App\Domain\Entity\Monitor;
use App\Domain\Entity\Tenant;
use App\Domain\Entity\TenantMembership;
use App\Domain\Entity\UserCredentials;
use App\Domain\Entity\Workspace;
use App\Domain\Repository\CheckResultRepositoryInterface;
use App\Domain\Repository\IdentityRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Repository\TenantMembershipRepositoryInterface;
use App\Domain\Repository\TenantRepositoryInterface;
use App\Domain\Repository\UserCredentialsRepositoryInterface;
use App\Domain\Repository\WorkspaceRepositoryInterface;
use App\Domain\Service\PasswordHasherInterface;
use App\Infrastructure\Security\IdentityUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ReportControllerTest extends WebTestCase
{
    private ?Connection $connection = null;
    private string $workspacePublicId = '';
    private int $workspaceId = 0;
    private string $siteId = '';

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement('DELETE FROM analytics_events');
            $this->connection->executeStatement('DELETE FROM alert_events');
            $this->connection->executeStatement('DELETE FROM alert_rules');
            $this->connection->executeStatement('DELETE FROM smtp_tests');
            $this->connection->executeStatement('DELETE FROM checks_results');
            $this->connection->executeStatement('DELETE FROM monitors');
            $this->connection->executeStatement('DELETE FROM workspaces');
            $this->connection->executeStatement('DELETE FROM tenant_memberships');
            $this->connection->executeStatement('DELETE FROM tenants');
            $this->connection->executeStatement('DELETE FROM user_credentials');
            $this->connection->executeStatement('DELETE FROM identities');
        }
        $this->connection = null;
        parent::tearDown();
    }

    private function createLoggedInUser(KernelBrowser $client): IdentityUser
    {
        $container = static::getContainer();
        $this->connection = $container->get(Connection::class);

        $tenantRepo = $container->get(TenantRepositoryInterface::class);
        $identityRepo = $container->get(IdentityRepositoryInterface::class);
        $credentialsRepo = $container->get(UserCredentialsRepositoryInterface::class);
        $membershipRepo = $container->get(TenantMembershipRepositoryInterface::class);
        $workspaceRepo = $container->get(WorkspaceRepositoryInterface::class);
        $hasher = $container->get(PasswordHasherInterface::class);

        $tenant = new Tenant('Test Tenant', 'test-tenant');
        $tenantRepo->save($tenant);

        $identity = new Identity('user@example.test');
        $identityRepo->save($identity);

        $credentials = new UserCredentials($identity->getId(), $hasher->hash('password'));
        $credentialsRepo->save($credentials);

        $membershipRepo->save(new TenantMembership($tenant->getId(), $identity->getId(), 'owner'));

        $workspace = new Workspace($tenant->getId(), 'Default');
        $workspaceRepo->save($workspace);
        $this->workspacePublicId = $workspace->getPublicId();
        $this->workspaceId = $workspace->getId();
        $this->siteId = $workspace->getSiteId();

        return new IdentityUser($identity, $credentials);
    }

    /** @return array{0: Monitor, 1: \DateTimeImmutable, 2: \DateTimeImmutable} */
    private function seedMonitorWithIncidentAndTraffic(): array
    {
        $container = static::getContainer();
        $monitorRepo = $container->get(MonitorRepositoryInterface::class);
        $checkResultRepo = $container->get(CheckResultRepositoryInterface::class);

        $monitor = new Monitor($this->workspaceId, 'Report Monitor', 'http', 'https://example.com', 5);
        $monitorRepo->save($monitor);

        $now = new \DateTimeImmutable();
        for ($i = 0; $i < 8; $i++) {
            $checkResultRepo->save(new CheckResult($monitor->getId(), 'up', 100, $now));
        }
        for ($i = 0; $i < 2; $i++) {
            $checkResultRepo->save(new CheckResult($monitor->getId(), 'down', 5000, $now, 'timeout'));
        }

        $triggeredAt = $now->modify('-60 minutes');
        $resolvedAt = $now->modify('-50 minutes');

        $this->connection->insert('alert_rules', [
            'monitor_id' => $monitor->getId(),
            'condition_type' => 'down_count',
            'threshold' => 1,
            'channel' => 'email',
            'recipient' => 'ops@example.test',
            'cooldown_minutes' => 60,
        ]);
        $ruleId = (int) $this->connection->lastInsertId();

        $this->connection->insert('alert_events', [
            'rule_id' => $ruleId,
            'status' => 'resolved',
            'triggered_at' => $triggeredAt->format('Y-m-d H:i:s'),
            'resolved_at' => $resolvedAt->format('Y-m-d H:i:s'),
            'notified' => 1,
        ]);

        // Baseline window (the 10 minutes before triggeredAt, equal to the
        // incident's own 10-minute duration): 5 pageviews.
        for ($i = 0; $i < 5; $i++) {
            $this->connection->insert('analytics_events', [
                'site_id' => $this->siteId,
                'path' => '/baseline',
                'session_id' => 'session-baseline-' . $i,
                'occurred_at' => $triggeredAt->modify('-5 minutes')->format('Y-m-d H:i:s'),
                'event_type' => 'pageview',
            ]);
        }

        // Incident window itself: 1 pageview.
        $this->connection->insert('analytics_events', [
            'site_id' => $this->siteId,
            'path' => '/during-incident',
            'session_id' => 'session-incident',
            'occurred_at' => $triggeredAt->modify('+5 minutes')->format('Y-m-d H:i:s'),
            'event_type' => 'pageview',
        ]);

        return [$monitor, $triggeredAt, $resolvedAt];
    }

    public function testReportShowsComputedUptimeMttrAndLostPageviews(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        [$monitor] = $this->seedMonitorWithIncidentAndTraffic();

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/monitor/' . $monitor->getPublicId() . '/report');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '80%');
        $this->assertSelectorTextContains('body', '10 min');
        $this->assertSelectorTextContains('body', 'down_count');
    }

    public function testReportPdfDownloadReturnsPdfDocument(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        [$monitor] = $this->seedMonitorWithIncidentAndTraffic();

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/monitor/' . $monitor->getPublicId() . '/report/pdf');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function testReportShowsLatencyTrafficCorrelation(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $container = static::getContainer();
        $monitorRepo = $container->get(MonitorRepositoryInterface::class);
        $checkResultRepo = $container->get(CheckResultRepositoryInterface::class);

        $monitor = new Monitor($this->workspaceId, 'Latency Monitor', 'http', 'https://example.com', 5);
        $monitorRepo->save($monitor);

        // Perfectly linear relationship across 3 distinct days: latency and
        // pageviews both scale 1:10 — Pearson correlation must come out as
        // exactly 1.0 ("Strong positive").
        $days = [
            ['daysAgo' => 3, 'latency' => 100, 'pageviews' => 10],
            ['daysAgo' => 2, 'latency' => 200, 'pageviews' => 20],
            ['daysAgo' => 1, 'latency' => 300, 'pageviews' => 30],
        ];

        foreach ($days as $day) {
            $checkedAt = (new \DateTimeImmutable())->modify('-' . $day['daysAgo'] . ' days')->setTime(12, 0);
            $checkResultRepo->save(new CheckResult($monitor->getId(), 'up', $day['latency'], $checkedAt));
            $checkResultRepo->save(new CheckResult($monitor->getId(), 'up', $day['latency'], $checkedAt));

            for ($i = 0; $i < $day['pageviews']; $i++) {
                $this->connection->insert('analytics_events', [
                    'site_id' => $this->siteId,
                    'path' => '/x',
                    'session_id' => 'session-' . $day['daysAgo'] . '-' . $i,
                    'occurred_at' => $checkedAt->format('Y-m-d H:i:s'),
                    'event_type' => 'pageview',
                ]);
            }
        }

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/monitor/' . $monitor->getPublicId() . '/report?period=30d');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Forte');
        $this->assertSelectorTextContains('body', '(1)');
    }

    public function testWorkspaceReportAggregatesAcrossMonitors(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        // Monitor A: 8 up / 2 down, one resolved incident with lost traffic (see helper).
        [$monitorA] = $this->seedMonitorWithIncidentAndTraffic();

        // Monitor B: 10 up / 0 down, no incidents — clean monitor.
        $monitorRepo = static::getContainer()->get(MonitorRepositoryInterface::class);
        $checkResultRepo = static::getContainer()->get(CheckResultRepositoryInterface::class);
        $monitorB = new Monitor($this->workspaceId, 'Clean Monitor', 'http', 'https://clean.example.com', 5);
        $monitorRepo->save($monitorB);
        for ($i = 0; $i < 10; $i++) {
            $checkResultRepo->save(new CheckResult($monitorB->getId(), 'up', 90, new \DateTimeImmutable()));
        }

        $crawler = $client->request('GET', '/workspace/' . $this->workspacePublicId . '/report');

        $this->assertResponseIsSuccessful();
        // Aggregate uptime: (8 + 10) up out of (10 + 10) checks = 90%.
        $this->assertSelectorTextContains('body', '90%');
        $this->assertSelectorTextContains('body', $monitorA->getName());
        $this->assertSelectorTextContains('body', $monitorB->getName());
    }

    public function testReportForMonitorFromAnotherWorkspaceIs404(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $container = static::getContainer();
        $monitorRepo = $container->get(MonitorRepositoryInterface::class);
        $workspaceRepo = $container->get(WorkspaceRepositoryInterface::class);

        $tenantId = $workspaceRepo->find($this->workspaceId)->getTenantId();
        $otherWorkspace = new Workspace($tenantId, 'Other Workspace');
        $workspaceRepo->save($otherWorkspace);

        $otherWorkspaceMonitor = new Monitor($otherWorkspace->getId(), 'Other Workspace Monitor', 'http', 'https://example.com', 5);
        $monitorRepo->save($otherWorkspaceMonitor);

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/monitor/' . $otherWorkspaceMonitor->getPublicId() . '/report');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testReportRequiresWorkspaceAccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        [$monitor] = $this->seedMonitorWithIncidentAndTraffic();

        $container = static::getContainer();
        $otherTenant = new Tenant('Other Tenant', 'other-tenant');
        $container->get(TenantRepositoryInterface::class)->save($otherTenant);
        $otherWorkspace = new Workspace($otherTenant->getId(), 'Default');
        $container->get(WorkspaceRepositoryInterface::class)->save($otherWorkspace);

        $client->request('GET', '/workspace/' . $otherWorkspace->getPublicId() . '/monitor/' . $monitor->getPublicId() . '/report');

        $this->assertResponseStatusCodeSame(403);
    }
}
