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
use App\Domain\Entity\Workspace;
use App\Domain\Repository\AlertEventRepositoryInterface;
use App\Domain\Repository\AlertRuleRepositoryInterface;
use App\Domain\Repository\IdentityRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Repository\TenantMembershipRepositoryInterface;
use App\Domain\Repository\TenantRepositoryInterface;
use App\Domain\Repository\UserCredentialsRepositoryInterface;
use App\Domain\Repository\WorkspaceRepositoryInterface;
use App\Domain\Service\PasswordHasherInterface;
use App\Infrastructure\Security\IdentityUser;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardOverviewControllerTest extends WebTestCase
{
    private ?Connection $connection = null;
    private string $siteId = '';
    private string $workspacePublicId = '';
    private int $workspaceId = 0;
    private int $tenantId = 0;

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement('DELETE FROM analytics_events');
            $this->connection->executeStatement('DELETE FROM alert_events');
            $this->connection->executeStatement('DELETE FROM alert_rules');
            $this->connection->executeStatement('DELETE FROM monitors');
            $this->connection->executeStatement('DELETE FROM workspaces');
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

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Aucun moniteur configuré');
    }

    public function testOverviewCountsMonitorsByStatus(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $monitorRepo = static::getContainer()->get(MonitorRepositoryInterface::class);
        $monitorRepo->save(new Monitor($this->workspaceId, 'No checks yet', 'http', 'https://example.com', 5));

        $crawler = $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSame('1', trim($crawler->filter('[data-summary="monitors-pending"] p.text-2xl')->text()));
        $this->assertSame('0', trim($crawler->filter('[data-summary="monitors-up"] p.text-2xl')->text()));
    }

    public function testAnalyticsRecapCountsOnlyThisWorkspacesPageviews(): void
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

        $crawler = $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard');

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

        $monitor = new Monitor($this->workspaceId, 'API', 'http', 'https://example.com', 5);
        $monitorRepo->save($monitor);

        $rule = new AlertRule($monitor->getId(), 'down_count', 1, 'email', 'ops@example.test');
        $ruleRepo->save($rule);

        $older = new AlertEvent($rule->getId(), 'triggered', new \DateTimeImmutable('-2 hours'));
        $eventRepo->save($older);

        $newer = new AlertEvent($rule->getId(), 'triggered', new \DateTimeImmutable('-10 minutes'));
        $newer->setStatus('resolved');
        $newer->setResolvedAt(new \DateTimeImmutable('-1 minute'));
        $eventRepo->save($newer);

        $crawler = $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard');

        $this->assertResponseIsSuccessful();
        $items = $crawler->filter('[data-activity-item]');
        $this->assertCount(2, $items);
        $this->assertStringContainsString('rétabli', $items->eq(0)->text());
        $this->assertStringContainsString('API', $items->eq(0)->text());
        $this->assertStringContainsString('en panne', $items->eq(1)->text());
    }

    public function testRecentActivityIsIsolatedByWorkspace(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $container = static::getContainer();
        $monitorRepo = $container->get(MonitorRepositoryInterface::class);
        $ruleRepo = $container->get(AlertRuleRepositoryInterface::class);
        $eventRepo = $container->get(AlertEventRepositoryInterface::class);
        $workspaceRepo = $container->get(WorkspaceRepositoryInterface::class);

        $otherWorkspace = new Workspace($this->tenantId, 'Other Workspace');
        $workspaceRepo->save($otherWorkspace);

        $otherMonitor = new Monitor($otherWorkspace->getId(), 'Other Workspace Monitor', 'http', 'https://example.com', 5);
        $monitorRepo->save($otherMonitor);
        $otherRule = new AlertRule($otherMonitor->getId(), 'down_count', 1, 'email', 'ops@example.test');
        $ruleRepo->save($otherRule);
        $eventRepo->save(new AlertEvent($otherRule->getId(), 'triggered', new \DateTimeImmutable()));

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Aucune activité récente');
    }

    public function testLiveGlobeEndpointCountsOnlineVisitorsByCountry(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $this->insertPageview('session-fr', 'FR', '/pricing', '-1 minute');
        $this->insertPageview('session-us', 'US', '/', '-2 minutes');

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard/live-globe');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame(2, $data['online']);
        $this->assertSame(2, $data['onlineCountries']);
        $this->assertCount(2, $data['pins']);
        $this->assertCount(2, $data['feed']);
        $this->assertSame('/pricing', $data['feed'][0]['path']);
        $this->assertSame('FR', $data['feed'][0]['country']);
    }

    public function testLiveGlobeEndpointIsIsolatedByTenant(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $this->insertPageview('session-mine', 'FR', '/', '-1 minute');
        $this->connection->insert('analytics_events', [
            'site_id' => 'someone-elses-site',
            'path' => '/secret',
            'country' => 'DE',
            'session_id' => 'session-other',
            'occurred_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            'event_type' => 'pageview',
        ]);

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard/live-globe');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame(1, $data['online']);
        $this->assertCount(1, $data['feed']);
        $this->assertSame('FR', $data['feed'][0]['country']);
    }

    public function testLiveGlobeExcludesNonPageviewEvents(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $this->insertPageview('session-a', 'FR', '/', '-1 minute');
        $this->connection->insert('analytics_events', [
            'site_id' => $this->siteId,
            'path' => '/checkout',
            'country' => 'FR',
            'session_id' => 'session-a',
            'occurred_at' => (new \DateTimeImmutable('-30 seconds'))->format('Y-m-d H:i:s'),
            'event_type' => 'event',
            'event_name' => 'purchase',
        ]);

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard/live-globe');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(1, $data['feed']);
        $this->assertSame('/', $data['feed'][0]['path']);
    }

    public function testLiveGlobePinsExcludeSessionsWithUnresolvedGeoIp(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $this->insertPageview('session-fr', 'FR', '/', '-1 minute');
        $this->connection->insert('analytics_events', [
            'site_id' => $this->siteId,
            'path' => '/',
            'country' => null,
            'session_id' => 'session-unresolved',
            'occurred_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            'event_type' => 'pageview',
        ]);

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard/live-globe');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame(2, $data['online'], 'both sessions still count toward "online"');
        $this->assertSame(1, $data['onlineCountries'], 'the null-country session must not inflate the country count');
        $this->assertCount(1, $data['pins']);
        $this->assertSame('FR', $data['pins'][0]['country']);
    }

    public function testLiveGlobeCountsHeartbeatsButExcludesThemFromTheFeed(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        // The pageview itself is outside the online window (visitor landed
        // 6 minutes ago) but a heartbeat 1 minute ago proves they're still
        // reading the page — this must keep the session "online" and its
        // pin on the globe, without resurrecting it in the pageview feed.
        $this->insertPageview('session-reader', 'DE', '/docs', '-6 minutes');
        $this->connection->insert('analytics_events', [
            'site_id' => $this->siteId,
            'path' => '/docs',
            'country' => 'DE',
            'session_id' => 'session-reader',
            'occurred_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            'event_type' => 'heartbeat',
        ]);

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard/live-globe');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame(1, $data['online'], 'a recent heartbeat keeps the session online');
        $this->assertSame(1, $data['onlineCountries']);
        $this->assertCount(1, $data['pins']);
        $this->assertSame('DE', $data['pins'][0]['country']);
        $this->assertSame([], $data['feed'], 'heartbeats are not page visits, so they must not appear in the feed');
    }

    public function testLiveGlobeFeedExcludesActivityOutsideTheOnlineWindow(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $this->insertPageview('session-old', 'FR', '/old-page', '-1 hour');

        $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard/live-globe');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame(0, $data['online']);
        $this->assertSame([], $data['feed'], 'the "who\'s online" feed must not show activity older than the online window');
    }

    public function testOverviewShowsAllTimeVisitsAndSevenDayCountryBreakdown(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $this->insertPageview('session-recent', 'FR', '/', '-1 day');
        $this->insertPageview('session-old', 'US', '/', '-10 days');

        $crawler = $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard');

        $this->assertResponseIsSuccessful();
        // All-time total includes both, regardless of the 7-day breakdown below.
        $this->assertStringContainsString('2 visites', trim($crawler->filter('[data-globe="all-time-visits"]')->text()));

        $countryItems = $crawler->filter('[data-globe="country-breakdown-item"]');
        $this->assertCount(1, $countryItems, 'only the pageview from the last 7 days should appear in the breakdown');
        $this->assertStringContainsString('France', $countryItems->eq(0)->text());
    }

    public function testUnresolvedGeoIpIsExcludedFromCountryBreakdownAndFeedLabel(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser());

        $this->connection->insert('analytics_events', [
            'site_id' => $this->siteId,
            'path' => '/',
            'country' => null,
            'session_id' => 'session-unresolved',
            'occurred_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            'event_type' => 'pageview',
        ]);

        $crawler = $client->request('GET', '/workspace/' . $this->workspacePublicId . '/dashboard');

        $this->assertResponseIsSuccessful();
        // No resolved country at all this period: the breakdown shouldn't render
        // a "??" placeholder row for the unresolved bucket.
        $this->assertCount(0, $crawler->filter('[data-globe="country-breakdown-item"]'));

        $feedItem = $crawler->filter('[data-globe="feed-item"]')->eq(0)->text();
        $this->assertStringNotContainsString('??', $feedItem);
    }

    private function insertPageview(string $sessionId, string $country, string $path, string $when): void
    {
        $this->connection->insert('analytics_events', [
            'site_id' => $this->siteId,
            'path' => $path,
            'country' => $country,
            'session_id' => $sessionId,
            'occurred_at' => (new \DateTimeImmutable($when))->format('Y-m-d H:i:s'),
            'event_type' => 'pageview',
        ]);
    }

    private function createLoggedInUser(): IdentityUser
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
        $this->tenantId = $tenant->getId();

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
}
