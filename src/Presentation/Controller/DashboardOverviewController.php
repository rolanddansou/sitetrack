<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\Repository\CheckResultRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Repository\TenantRepositoryInterface;
use App\Infrastructure\Activity\RecentActivityProvider;
use App\Infrastructure\Analytics\AnalyticsQueryService;
use App\Infrastructure\Analytics\LiveGlobeProvider;
use App\Infrastructure\Security\CurrentTenantResolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardOverviewController extends AbstractController
{
    public function __construct(
        private CurrentTenantResolver $currentTenantResolver,
        private TenantRepositoryInterface $tenantRepository,
        private MonitorRepositoryInterface $monitorRepository,
        private CheckResultRepositoryInterface $checkResultRepository,
        private RecentActivityProvider $recentActivityProvider,
        private AnalyticsQueryService $analyticsQuery,
        private LiveGlobeProvider $liveGlobeProvider,
        private Connection $connection
    ) {}

    #[Route('/dashboard', name: 'dashboard_index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantRepository->find($this->currentTenantResolver->resolve());
        if ($tenant === null) {
            throw $this->createNotFoundException('Tenant not found');
        }
        $siteId = $tenant->getSiteId();

        $allTimeVisits = $this->analyticsQuery->getAllTimeVisits($siteId);
        // Unresolved GeoIP groups into its own "country" row (label === null) —
        // drop it here rather than showing a confusing "??" placeholder in a
        // breakdown that's specifically framed as "by country".
        $countryBreakdown7d = array_values(array_filter(
            $this->analyticsQuery->groupBy($siteId, new \DateTimeImmutable('-7 days'), new \DateTimeImmutable(), 'country'),
            static fn (array $row): bool => $row['label'] !== null
        ));

        return $this->render('dashboard/overview.html.twig', [
            'monitorSummary' => $this->buildMonitorSummary($tenant->getId() ?? 0),
            'analyticsSummary' => $this->buildAnalyticsSummary($siteId),
            'recentActivity' => $this->buildRecentActivity($tenant->getId() ?? 0),
            'liveGlobe' => $this->buildLiveGlobePayload($siteId),
            'allTimeVisits' => $allTimeVisits['total'],
            'visitsSince' => $allTimeVisits['since'],
            'countryBreakdown7d' => $countryBreakdown7d,
        ]);
    }

    #[Route('/dashboard/live-globe', name: 'dashboard_live_globe', methods: ['GET'])]
    public function liveGlobe(): JsonResponse
    {
        $tenant = $this->tenantRepository->find($this->currentTenantResolver->resolve());
        if ($tenant === null) {
            throw $this->createNotFoundException('Tenant not found');
        }

        return new JsonResponse($this->buildLiveGlobePayload($tenant->getSiteId()));
    }

    /**
     * Shared by the initial page render (avoids an empty-globe flash before
     * the first poll) and the 5s-polled JSON endpoint, so both always agree
     * on shape. The underlying data is cache-aside (see LiveGlobeProvider) so
     * repeated /dashboard loads don't each pay for fresh queries; only the
     * relative-time formatting happens here, per request, so it's never stale.
     *
     * @return array{online: int, onlineCountries: int, pins: array<int, array{country: ?string, count: int}>, feed: array<int, array{country: ?string, path: string, relativeTime: string}>}
     */
    private function buildLiveGlobePayload(string $siteId): array
    {
        $raw = $this->liveGlobeProvider->getPayload($siteId);

        return [
            'online' => $raw['online'],
            'onlineCountries' => $raw['onlineCountries'],
            'pins' => $raw['pins'],
            'feed' => array_map(fn (array $item): array => [
                'country' => $item['country'],
                'path' => $item['path'],
                'relativeTime' => $this->formatRelativeTime($item['occurredAt']),
            ], $raw['feed']),
        ];
    }

    /**
     * @return array<int, array{monitorName: string, monitorPublicId: string, status: string, relativeTime: string}>
     */
    private function buildRecentActivity(int $tenantId): array
    {
        return array_map(fn (array $item): array => [
            'monitorName' => $item['monitorName'],
            'monitorPublicId' => $item['monitorPublicId'],
            'status' => $item['status'],
            'relativeTime' => $this->formatRelativeTime($item['occurredAt']),
        ], $this->recentActivityProvider->getRecentActivity($tenantId));
    }

    private function formatRelativeTime(\DateTimeImmutable $time): string
    {
        $seconds = max(0, (new \DateTimeImmutable())->getTimestamp() - $time->getTimestamp());

        if ($seconds < 60) {
            return 'à l\'instant';
        }
        if ($seconds < 3600) {
            return sprintf('il y a %d min', intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return sprintf('il y a %dh', intdiv($seconds, 3600));
        }

        return sprintf('il y a %dj', intdiv($seconds, 86400));
    }

    /**
     * @return array{up: int, down: int, pending: int, total: int}
     */
    private function buildMonitorSummary(int $tenantId): array
    {
        $summary = ['up' => 0, 'down' => 0, 'pending' => 0, 'total' => 0];

        foreach ($this->monitorRepository->findActiveMonitorsByTenant($tenantId) as $monitor) {
            $summary['total']++;
            $summary[$this->resolveMonitorStatus($monitor->getId() ?? 0, $monitor->getType())]++;
        }

        return $summary;
    }

    private function resolveMonitorStatus(int $monitorId, string $type): string
    {
        if ($type === 'smtp') {
            $smtp = $this->connection->createQueryBuilder()
                ->select('status')
                ->from('smtp_tests')
                ->where('monitor_id = :monitorId')
                ->setParameter('monitorId', $monitorId)
                ->orderBy('sent_at', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            return match (true) {
                $smtp === false, $smtp === 'sent' => 'pending',
                $smtp === 'delivered' => 'up',
                default => 'down', // 'failed', 'timeout'
            };
        }

        $recent = $this->checkResultRepository->findRecentResults($monitorId, 1);
        if (count($recent) === 0) {
            return 'pending';
        }

        return $recent[0]->getStatus() === 'up' ? 'up' : 'down';
    }

    /**
     * @return array{visitors: int, pageviews: int, bounceRate: float}
     */
    private function buildAnalyticsSummary(string $siteId): array
    {
        $start = new \DateTimeImmutable('-7 days');
        $end = new \DateTimeImmutable();

        $visitors = $this->analyticsQuery->countUniqueVisitors($siteId, $start, $end);
        $pageviews = $this->analyticsQuery->countPageviews($siteId, $start, $end);
        $bounceRate = $this->analyticsQuery->calculateBounceRate($siteId, $start, $end, $visitors);

        return [
            'visitors' => $visitors,
            'pageviews' => $pageviews,
            'bounceRate' => $bounceRate,
        ];
    }
}
