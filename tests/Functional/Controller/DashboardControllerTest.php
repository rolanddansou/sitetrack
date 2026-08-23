<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Domain\Entity\Identity;
use App\Domain\Entity\Monitor;
use App\Domain\Entity\Tenant;
use App\Domain\Entity\TenantMembership;
use App\Domain\Entity\UserCredentials;
use App\Domain\Repository\IdentityRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Repository\TenantMembershipRepositoryInterface;
use App\Domain\Repository\TenantRepositoryInterface;
use App\Domain\Repository\UserCredentialsRepositoryInterface;
use App\Domain\Service\PasswordHasherInterface;
use App\Infrastructure\Security\IdentityUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardControllerTest extends WebTestCase
{
    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement('DELETE FROM analytics_events');
            $this->connection->executeStatement('DELETE FROM monitors');
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
        $hasher = $container->get(PasswordHasherInterface::class);

        $tenant = new Tenant('Test Tenant', 'test-tenant');
        $tenantRepo->save($tenant);

        $identity = new Identity('user@example.test');
        $identityRepo->save($identity);

        $credentials = new UserCredentials($identity->getId(), $hasher->hash('password'));
        $credentialsRepo->save($credentials);

        $membershipRepo->save(new TenantMembership($tenant->getId(), $identity->getId(), 'owner'));

        return new IdentityUser($identity, $credentials);
    }

    public function testIndexPageLoads(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $client->request('GET', '/dashboard');

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

    public function testCrossTenantMonitorAccessIsDenied(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $monitorRepo = static::getContainer()->get(MonitorRepositoryInterface::class);
        $otherTenantMonitor = new Monitor(999999, 'Other Tenant Monitor', 'http', 'https://example.com', 5);
        $monitorRepo->save($otherTenantMonitor);

        $client->request('GET', '/monitor/' . $otherTenantMonitor->getPublicId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testMonitorShowUsesPublicIdNotDatabaseId(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $monitorRepo = static::getContainer()->get(MonitorRepositoryInterface::class);
        $membershipRepo = static::getContainer()->get(TenantMembershipRepositoryInterface::class);
        $tenantId = $membershipRepo->findByIdentityId(
            static::getContainer()->get(IdentityRepositoryInterface::class)->findByEmail('user@example.test')->getId()
        )[0]->getTenantId();

        $monitor = new Monitor($tenantId, 'My Monitor', 'http', 'https://example.com', 5);
        $monitorRepo->save($monitor);

        // The route only accepts the generated UUID — the raw database id must not resolve.
        $client->request('GET', '/monitor/' . $monitor->getId());
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/monitor/' . $monitor->getPublicId());
        $this->assertResponseIsSuccessful();
    }

    public function testAnalyticsPageShowsTenantSiteIdAndItsOwnEvents(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $membershipRepo = static::getContainer()->get(TenantMembershipRepositoryInterface::class);
        $identityRepo = static::getContainer()->get(IdentityRepositoryInterface::class);
        $tenantRepo = static::getContainer()->get(TenantRepositoryInterface::class);

        $identityId = $identityRepo->findByEmail('user@example.test')->getId();
        $tenantId = $membershipRepo->findByIdentityId($identityId)[0]->getTenantId();
        $siteId = $tenantRepo->find($tenantId)->getSiteId();

        // A pageview for this tenant's own site_id...
        $this->connection->insert('analytics_events', [
            'site_id' => $siteId,
            'path' => '/pricing',
            'referrer' => null,
            'country' => null,
            'session_id' => 'session-a',
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        // ...and one for a different site_id, which must not leak into these counts.
        $this->connection->insert('analytics_events', [
            'site_id' => 'someone-elses-site',
            'path' => '/other',
            'referrer' => null,
            'country' => null,
            'session_id' => 'session-b',
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $crawler = $client->request('GET', '/analytics');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('code', $siteId);

        $stats = $crawler->filter('span.text-2xl');
        $this->assertSame('1', trim($stats->eq(0)->text())); // Unique Visitors
        $this->assertSame('1', trim($stats->eq(1)->text())); // Pageviews
    }
}
