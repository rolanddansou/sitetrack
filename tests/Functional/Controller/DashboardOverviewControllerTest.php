<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Domain\Entity\AlertEvent;
use App\Domain\Entity\AlertRule;
use App\Domain\Entity\Identity;
use App\Domain\Entity\Monitor;
use App\Domain\Entity\Tenant;
use App\Domain\Entity\TenantMembership;
use App\Domain\Entity\UserCredentials;
use App\Domain\Repository\AlertEventRepositoryInterface;
use App\Domain\Repository\AlertRuleRepositoryInterface;
use App\Domain\Repository\IdentityRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Repository\TenantMembershipRepositoryInterface;
use App\Domain\Repository\TenantRepositoryInterface;
use App\Domain\Repository\UserCredentialsRepositoryInterface;
use App\Domain\Service\PasswordHasherInterface;
use App\Infrastructure\Security\IdentityUser;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardOverviewControllerTest extends WebTestCase
{
    private ?Connection $connection = null;
    private string $siteId = '';

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement('DELETE FROM analytics_events');
            $this->connection->executeStatement('DELETE FROM alert_events');
            $this->connection->executeStatement('DELETE FROM alert_rules');
            $this->connection->executeStatement('DELETE FROM monitors');
            $this->connection->executeStatement('DELETE FROM tenant_memberships');
            $this->connection->executeStatement('DELETE FROM tenants');
            $this->connection->executeStatement('DELETE FROM user_credentials');
            $this->connection->executeStatement('DELETE FROM identities');
        }
        $this->connection = null;
        static::getContainer()->get(CacheItemPoolInterface::class)->clear();
        parent::tearDown();
    }

    public function testOverviewShowsNoMonitorsWhenNoneConfigured(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Aucun moniteur configuré');
    }

    public function testOverviewCountsMonitorsByStatus(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $container = static::getContainer();
        $monitorRepo = $container->get(MonitorRepositoryInterface::class);
        $membershipRepo = $container->get(TenantMembershipRepositoryInterface::class);
        $tenantId = $membershipRepo->findByIdentityId(
            $container->get(IdentityRepositoryInterface::class)->findByEmail('user@example.test')->getId()
        )[0]->getTenantId();

        $monitorRepo->save(new Monitor($tenantId, 'No checks yet', 'http', 'https://example.com', 5));

        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSame('1', trim($crawler->filter('[data-summary="monitors-pending"] p.text-2xl')->text()));
        $this->assertSame('0', trim($crawler->filter('[data-summary="monitors-up"] p.text-2xl')->text()));
    }

    public function testAnalyticsRecapCountsOnlyThisTenantsPageviews(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $this->connection->insert('analytics_events', [
            'site_id' => $this->siteId,
            'path' => '/',
            'session_id' => 'session-a',
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'event_type' => 'pageview',
        ]);
        $this->connection->insert('analytics_events', [
            'site_id' => 'someone-elses-site',
            'path' => '/',
            'session_id' => 'session-b',
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'event_type' => 'pageview',
        ]);

        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSame('1', trim($crawler->filter('[data-summary="analytics-visitors"] p.text-2xl')->text()));
        $this->assertSame('1', trim($crawler->filter('[data-summary="analytics-pageviews"] p.text-2xl')->text()));
    }

    public function testRecentActivityShowsTriggeredAndResolvedAlertsMostRecentFirst(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $container = static::getContainer();
        $monitorRepo = $container->get(MonitorRepositoryInterface::class);
        $ruleRepo = $container->get(AlertRuleRepositoryInterface::class);
        $eventRepo = $container->get(AlertEventRepositoryInterface::class);
        $membershipRepo = $container->get(TenantMembershipRepositoryInterface::class);
        $tenantId = $membershipRepo->findByIdentityId(
            $container->get(IdentityRepositoryInterface::class)->findByEmail('user@example.test')->getId()
        )[0]->getTenantId();

        $monitor = new Monitor($tenantId, 'API', 'http', 'https://example.com', 5);
        $monitorRepo->save($monitor);

        $rule = new AlertRule($monitor->getId(), 'down_count', 1, 'email', 'ops@example.test');
        $ruleRepo->save($rule);

        $older = new AlertEvent($rule->getId(), 'triggered', new \DateTimeImmutable('-2 hours'));
        $eventRepo->save($older);

        $newer = new AlertEvent($rule->getId(), 'triggered', new \DateTimeImmutable('-10 minutes'));
        $newer->setStatus('resolved');
        $newer->setResolvedAt(new \DateTimeImmutable('-1 minute'));
        $eventRepo->save($newer);

        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $items = $crawler->filter('[data-activity-item]');
        $this->assertCount(2, $items);
        $this->assertStringContainsString('rétabli', $items->eq(0)->text());
        $this->assertStringContainsString('API', $items->eq(0)->text());
        $this->assertStringContainsString('en panne', $items->eq(1)->text());
    }

    public function testRecentActivityIsIsolatedByTenant(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $container = static::getContainer();
        $monitorRepo = $container->get(MonitorRepositoryInterface::class);
        $ruleRepo = $container->get(AlertRuleRepositoryInterface::class);
        $eventRepo = $container->get(AlertEventRepositoryInterface::class);

        $otherMonitor = new Monitor(999999, 'Other Tenant Monitor', 'http', 'https://example.com', 5);
        $monitorRepo->save($otherMonitor);
        $otherRule = new AlertRule($otherMonitor->getId(), 'down_count', 1, 'email', 'ops@example.test');
        $ruleRepo->save($otherRule);
        $eventRepo->save(new AlertEvent($otherRule->getId(), 'triggered', new \DateTimeImmutable()));

        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Aucune activité récente');
    }

    private function createLoggedInUser(): IdentityUser
    {
        $container = static::getContainer();
        $this->connection = $container->get(Connection::class);

        $tenantRepo = $container->get(TenantRepositoryInterface::class);
        $identityRepo = $container->get(IdentityRepositoryInterface::class);
        $credentialsRepo = $container->get(UserCredentialsRepositoryInterface::class);
        $membershipRepo = $container->get(TenantMembershipRepositoryInterface::class);
        $hasher = $container->get(PasswordHasherInterface::class);

        $tenant = new Tenant('Test Tenant', 'test-tenant');
        $tenantRepo->save($tenant);
        $this->siteId = $tenant->getSiteId();

        $identity = new Identity('user@example.test');
        $identityRepo->save($identity);

        $credentials = new UserCredentials($identity->getId(), $hasher->hash('password'));
        $credentialsRepo->save($credentials);

        $membershipRepo->save(new TenantMembership($tenant->getId(), $identity->getId(), 'owner'));

        return new IdentityUser($identity, $credentials);
    }
}
