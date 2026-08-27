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

class DashboardControllerTest extends WebTestCase
{
    private ?Connection $connection = null;
    private string $workspacePublicId = '';
    private int $workspaceId = 0;

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

        return new IdentityUser($identity, $credentials);
    }

    public function testAvailabilityPageLoads(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/availability');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Uptime & SMTP Monitors');
        $this->assertSelectorTextContains('h3', 'No monitors configured');
    }

    public function testAnonymousRequestRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        $this->assertResponseRedirects('/login');
    }

    public function testCrossTenantWorkspaceAccessIsDenied(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $container = static::getContainer();
        $otherTenant = new Tenant('Other Tenant', 'other-tenant');
        $container->get(TenantRepositoryInterface::class)->save($otherTenant);
        $otherWorkspace = new Workspace($otherTenant->getId(), 'Default');
        $container->get(WorkspaceRepositoryInterface::class)->save($otherWorkspace);

        $client->request('GET', '/workspace/' . $otherWorkspace->getPublicId() . '/availability');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testMonitorFromAnotherWorkspaceInSameTenantIsNotFound(): void
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

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/monitor/' . $otherWorkspaceMonitor->getPublicId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMonitorShowUsesPublicIdNotDatabaseId(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $monitorRepo = static::getContainer()->get(MonitorRepositoryInterface::class);

        $monitor = new Monitor($this->workspaceId, 'My Monitor', 'http', 'https://example.com', 5);
        $monitorRepo->save($monitor);

        // The route only accepts the generated UUID — the raw database id must not resolve.
        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/monitor/' . $monitor->getId());
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/monitor/' . $monitor->getPublicId());
        $this->assertResponseIsSuccessful();
    }

    public function testLiveEndpointReturnsHttpMonitorShape(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $container = static::getContainer();
        $monitorRepo = $container->get(MonitorRepositoryInterface::class);
        $checkResultRepo = $container->get(CheckResultRepositoryInterface::class);

        $monitor = new Monitor($this->workspaceId, 'HTTP Monitor', 'http', 'https://example.com', 5);
        $monitorRepo->save($monitor);
        $checkResultRepo->save(new CheckResult($monitor->getId(), 'up', 123, new \DateTimeImmutable('2026-01-01 12:00:00')));

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/monitor/' . $monitor->getPublicId() . '/live');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame('http', $data['monitorType']);
        $this->assertSame('up', $data['status']['state']);
        $this->assertIsArray($data['chart']);
        $this->assertCount(1, $data['checks']);
        $this->assertSame(['checkedAt', 'status', 'responseTimeMs', 'errorMessage'], array_keys($data['checks'][0]));
        $this->assertSame(123, $data['checks'][0]['responseTimeMs']);
        $this->assertSame([], $data['incidents']);
    }

    public function testLiveEndpointReturnsSmtpMonitorShape(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $container = static::getContainer();
        $monitorRepo = $container->get(MonitorRepositoryInterface::class);
        $this->connection = $container->get(Connection::class);

        $monitor = new Monitor($this->workspaceId, 'SMTP Monitor', 'smtp', 'smtp://example.com', 5);
        $monitorRepo->save($monitor);

        $this->connection->insert('smtp_tests', [
            'id' => bin2hex(random_bytes(8)),
            'monitor_id' => $monitor->getId(),
            'status' => 'delivered',
            'sent_at' => '2026-01-01 12:00:00',
            'received_at' => '2026-01-01 12:00:04',
            'delivery_time_seconds' => 4,
            'spf_passed' => 1,
            'dkim_passed' => 1,
        ]);

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/monitor/' . $monitor->getPublicId() . '/live');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame('smtp', $data['monitorType']);
        $this->assertSame('up', $data['status']['state']);
        $this->assertCount(1, $data['smtpTests']);
        $this->assertSame('delivered', $data['smtpTests'][0]['status']);
        $this->assertTrue($data['smtpTests'][0]['spfPassed']);
        $this->assertTrue($data['smtpTests'][0]['dkimPassed']);
    }

    public function testLiveEndpointIncludesIncidents(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $container = static::getContainer();
        $monitorRepo = $container->get(MonitorRepositoryInterface::class);
        $this->connection = $container->get(Connection::class);

        $monitor = new Monitor($this->workspaceId, 'HTTP Monitor', 'http', 'https://example.com', 5);
        $monitorRepo->save($monitor);

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
            'status' => 'triggered',
            'triggered_at' => '2026-01-01 12:00:00',
            'notified' => 1,
        ]);

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/monitor/' . $monitor->getPublicId() . '/live');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(1, $data['incidents']);
        $incident = $data['incidents'][0];
        $this->assertSame(['id', 'conditionType', 'triggeredAt', 'notified', 'status', 'resolvedAt'], array_keys($incident));
        $this->assertSame('down_count', $incident['conditionType']);
        $this->assertTrue($incident['notified']);
        $this->assertSame('triggered', $incident['status']);
        $this->assertNull($incident['resolvedAt']);
    }

    public function testLiveEndpointForMonitorFromAnotherWorkspaceIs404(): void
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

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/monitor/' . $otherWorkspaceMonitor->getPublicId() . '/live');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLiveEndpointRequiresWorkspaceAccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $container = static::getContainer();
        $monitorRepo = $container->get(MonitorRepositoryInterface::class);

        $monitor = new Monitor($this->workspaceId, 'HTTP Monitor', 'http', 'https://example.com', 5);
        $monitorRepo->save($monitor);

        $otherTenant = new Tenant('Other Tenant', 'other-tenant');
        $container->get(TenantRepositoryInterface::class)->save($otherTenant);
        $otherWorkspace = new Workspace($otherTenant->getId(), 'Default');
        $container->get(WorkspaceRepositoryInterface::class)->save($otherWorkspace);

        $client->request('GET', '/workspace/' . $otherWorkspace->getPublicId() . '/monitor/' . $monitor->getPublicId() . '/live');

        $this->assertResponseStatusCodeSame(403);
    }
}
