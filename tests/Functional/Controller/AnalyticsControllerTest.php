<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Domain\Entity\Identity;
use App\Domain\Entity\Tenant;
use App\Domain\Entity\TenantMembership;
use App\Domain\Entity\UserCredentials;
use App\Domain\Repository\IdentityRepositoryInterface;
use App\Domain\Repository\TenantMembershipRepositoryInterface;
use App\Domain\Repository\TenantRepositoryInterface;
use App\Domain\Repository\UserCredentialsRepositoryInterface;
use App\Domain\Service\PasswordHasherInterface;
use App\Infrastructure\Security\IdentityUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AnalyticsControllerTest extends WebTestCase
{
    private ?Connection $connection = null;
    private string $siteId = '';

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement('DELETE FROM analytics_events');
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
        $this->siteId = $tenant->getSiteId();

        $identity = new Identity('user@example.test');
        $identityRepo->save($identity);

        $credentials = new UserCredentials($identity->getId(), $hasher->hash('password'));
        $credentialsRepo->save($credentials);

        $membershipRepo->save(new TenantMembership($tenant->getId(), $identity->getId(), 'owner'));

        return new IdentityUser($identity, $credentials);
    }

    private function insertEvent(array $overrides = []): void
    {
        $this->connection->insert('analytics_events', array_merge([
            'site_id' => $this->siteId,
            'path' => '/pricing',
            'referrer' => null,
            'country' => null,
            'session_id' => 'session-' . bin2hex(random_bytes(4)),
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'event_type' => 'pageview',
        ], $overrides));
    }

    public function testAnalyticsPageShowsTenantSiteIdAndOwnEventsOnly(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $this->insertEvent(['session_id' => 'session-a']);
        // A different site's event must not leak into these counts.
        $this->insertEvent(['site_id' => 'someone-elses-site', 'session_id' => 'session-b']);

        $crawler = $client->request('GET', '/analytics');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('code', $this->siteId);
        $this->assertSame('1', trim($crawler->filter('[data-kpi="visitors"] p.text-2xl')->text()));
        $this->assertSame('1', trim($crawler->filter('[data-kpi="pageviews"] p.text-2xl')->text()));
    }

    public function testDateRangeFilterExcludesEventsOutsideRange(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $this->insertEvent(['session_id' => 'session-today']);
        $this->insertEvent([
            'session_id' => 'session-old',
            'occurred_at' => (new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s'),
        ]);

        $crawler = $client->request('GET', '/analytics?period=today');

        $this->assertResponseIsSuccessful();
        $this->assertSame('1', trim($crawler->filter('[data-kpi="pageviews"] p.text-2xl')->text()));
    }

    public function testCustomEventTypeDoesNotInflatePageviewCount(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $this->insertEvent(['session_id' => 'session-a', 'event_type' => 'pageview']);
        $this->insertEvent(['session_id' => 'session-b', 'event_type' => 'event']);

        $crawler = $client->request('GET', '/analytics');

        $this->assertResponseIsSuccessful();
        $this->assertSame('1', trim($crawler->filter('[data-kpi="visitors"] p.text-2xl')->text()));
        $this->assertSame('1', trim($crawler->filter('[data-kpi="pageviews"] p.text-2xl')->text()));
    }

    public function testBounceRateCalculation(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        // Session A: a single pageview — bounces.
        $this->insertEvent(['session_id' => 'session-a']);
        // Session B: two pageviews two minutes apart (both in the past, so
        // both fall inside the query's "up to now" window) — does not bounce.
        $this->insertEvent(['session_id' => 'session-b', 'occurred_at' => (new \DateTimeImmutable('-3 minutes'))->format('Y-m-d H:i:s')]);
        $this->insertEvent(['session_id' => 'session-b', 'occurred_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);

        $crawler = $client->request('GET', '/analytics');

        $this->assertResponseIsSuccessful();
        $this->assertSame('50%', trim($crawler->filter('[data-kpi="bounce-rate"] p.text-2xl')->text()));
    }

    public function testOnlineNowEndpointReturnsRecentSessionCountOnly(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $this->insertEvent(['session_id' => 'session-recent']);
        $this->insertEvent([
            'session_id' => 'session-stale',
            'occurred_at' => (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s'),
        ]);

        $client->request('GET', '/analytics/online-now');

        $this->assertResponseIsSuccessful();
        $this->assertJsonStringEqualsJsonString('{"count":1}', $client->getResponse()->getContent());
    }
}
