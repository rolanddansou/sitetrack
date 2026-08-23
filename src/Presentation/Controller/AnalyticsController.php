<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\Repository\TenantRepositoryInterface;
use App\Infrastructure\Analytics\AnalyticsQueryService;
use App\Infrastructure\Analytics\ChannelClassifier;
use App\Infrastructure\Security\CurrentTenantResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AnalyticsController extends AbstractController
{
    private const PERIODS = ['today', '24h', '7d', '30d'];

    /**
     * Granularities allowed per period, first entry is the default. 30d has
     * no 'hourly' option — that would mean ~720 chart buckets/DB rows for a
     * single page load.
     */
    private const ALLOWED_GRANULARITIES_BY_PERIOD = [
        'today' => ['hourly', 'daily'],
        '24h' => ['hourly', 'daily'],
        '7d' => ['daily', 'hourly'],
        '30d' => ['daily'],
    ];

    /** Columns that {@see groupBy()} is allowed to select — never build this from request input. */
    private const GROUPABLE_COLUMNS = ['referrer', 'utm_campaign', 'path', 'country', 'region', 'city', 'browser', 'os', 'device'];

    /** "Online now" is a recent-activity proxy, not a real-time heartbeat — event.js sends nothing while a visitor is idle on a page. */
    private const ONLINE_WINDOW_MINUTES = 5;

    public function __construct(
        private CurrentTenantResolver $currentTenantResolver,
        private TenantRepositoryInterface $tenantRepository,
        private Connection $connection,
        private ChannelClassifier $channelClassifier,
        private AnalyticsQueryService $analyticsQuery
    ) {}

    #[Route('/analytics', name: 'analytics_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantRepository->find($this->currentTenantResolver->resolve());
        if ($tenant === null) {
            throw $this->createNotFoundException('Tenant not found');
        }
        $siteId = $tenant->getSiteId();

        $period = $this->resolvePeriod((string) $request->query->get('period', ''));
        $granularity = $this->resolveGranularity($period, (string) $request->query->get('granularity', ''));
        [$start, $end] = $this->resolveRange($period);
        [$prevStart, $prevEnd] = $this->resolvePreviousRange($start, $end);

        $uniqueVisitors = $this->analyticsQuery->countUniqueVisitors($siteId, $start, $end);
        $pageviews = $this->analyticsQuery->countPageviews($siteId, $start, $end);
        $bounceRate = $this->analyticsQuery->calculateBounceRate($siteId, $start, $end, $uniqueVisitors);
        $sessionDurationSeconds = $this->calculateAvgSessionDuration($siteId, $start, $end);

        $prevUniqueVisitors = $this->analyticsQuery->countUniqueVisitors($siteId, $prevStart, $prevEnd);

        return $this->render('dashboard/analytics.html.twig', [
            'siteId' => $siteId,
            'period' => $period,
            'granularity' => $granularity,
            'uniqueVisitors' => $uniqueVisitors,
            'pageviews' => $pageviews,
            'bounceRate' => $bounceRate,
            'avgSessionDuration' => $this->formatDuration($sessionDurationSeconds),
            'onlineNow' => $this->countOnlineNow($siteId),
            'visitorsDelta' => $this->calculateDelta($uniqueVisitors, $prevUniqueVisitors),
            'pageviewsDelta' => $this->calculateDelta($pageviews, $this->analyticsQuery->countPageviews($siteId, $prevStart, $prevEnd)),
            'bounceRateDelta' => $this->calculateDelta($bounceRate, $this->analyticsQuery->calculateBounceRate($siteId, $prevStart, $prevEnd, $prevUniqueVisitors), lowerIsBetter: true),
            'sessionTimeDelta' => $this->calculateDelta($sessionDurationSeconds, $this->calculateAvgSessionDuration($siteId, $prevStart, $prevEnd)),
            'chartData' => $this->buildTimeSeries($siteId, $start, $end, $granularity),
            'channels' => $this->buildChannelChartData($siteId, $start, $end),
            'topReferrers' => $this->groupBy($siteId, $start, $end, 'referrer'),
            'topCampaigns' => $this->groupBy($siteId, $start, $end, 'utm_campaign'),
            'topPages' => $this->groupBy($siteId, $start, $end, 'path'),
            'entryPages' => $this->buildEntryPages($siteId, $start, $end),
            'countries' => $this->groupBy($siteId, $start, $end, 'country'),
            'regions' => $this->groupBy($siteId, $start, $end, 'region'),
            'cities' => $this->groupBy($siteId, $start, $end, 'city'),
            'browsers' => $this->groupBy($siteId, $start, $end, 'browser'),
            'oses' => $this->groupBy($siteId, $start, $end, 'os'),
            'devices' => $this->groupBy($siteId, $start, $end, 'device'),
        ]);
    }

    #[Route('/analytics/online-now', name: 'analytics_online_now', methods: ['GET'])]
    public function onlineNow(): JsonResponse
    {
        $tenant = $this->tenantRepository->find($this->currentTenantResolver->resolve());
        if ($tenant === null) {
            throw $this->createNotFoundException('Tenant not found');
        }

        return new JsonResponse(['count' => $this->countOnlineNow($tenant->getSiteId())]);
    }

    private function resolvePeriod(string $period): string
    {
        return in_array($period, self::PERIODS, true) ? $period : '7d';
    }

    private function resolveGranularity(string $period, string $granularity): string
    {
        $allowed = self::ALLOWED_GRANULARITIES_BY_PERIOD[$period];

        return in_array($granularity, $allowed, true) ? $granularity : $allowed[0];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolveRange(string $period): array
    {
        $now = new \DateTimeImmutable();
        $start = match ($period) {
            'today' => $now->setTime(0, 0),
            '24h' => $now->modify('-24 hours'),
            '30d' => $now->modify('-30 days'),
            default => $now->modify('-7 days'),
        };

        return [$start, $now];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolvePreviousRange(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $duration = $start->diff($end);

        return [$start->sub($duration), $start];
    }

    /**
     * @return array{percent: int, up: bool, good: bool}|null
     */
    private function calculateDelta(int|float $current, int|float $previous, bool $lowerIsBetter = false): ?array
    {
        if ($previous == 0) {
            return null;
        }

        $percent = (int) round((($current - $previous) / $previous) * 100);
        if ($percent === 0) {
            return null;
        }

        $up = $percent > 0;

        return [
            'percent' => abs($percent),
            'up' => $up,
            'good' => $lowerIsBetter ? !$up : $up,
        ];
    }

    /**
     * SQLite/MySQL/Postgres each spell "truncate a datetime to hour/day" differently,
     * and there's no portable Doctrine QueryBuilder helper for it outside the ORM/DQL layer.
     */
    private function dateBucketExpr(string $column, string $granularity): string
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            return $granularity === 'hourly'
                ? sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d %%H:00:00')", $column)
                : sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d')", $column);
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return $granularity === 'hourly'
                ? sprintf("to_char(%s, 'YYYY-MM-DD HH24:00:00')", $column)
                : sprintf("to_char(%s, 'YYYY-MM-DD')", $column);
        }

        return $granularity === 'hourly'
            ? sprintf("strftime('%%Y-%%m-%%d %%H:00:00', %s)", $column)
            : sprintf("strftime('%%Y-%%m-%%d', %s)", $column);
    }

    /** Same portability problem as {@see dateBucketExpr()}, for "seconds between two datetimes". */
    private function secondsBetweenExpr(string $minColumn, string $maxColumn): string
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf('TIMESTAMPDIFF(SECOND, %s, %s)', $minColumn, $maxColumn);
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return sprintf('EXTRACT(EPOCH FROM (%s - %s))', $maxColumn, $minColumn);
        }

        return sprintf('(julianday(%s) - julianday(%s)) * 86400', $maxColumn, $minColumn);
    }

    private function calculateAvgSessionDuration(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $avgSeconds = $this->connection->executeQuery(
            sprintf(
                'SELECT AVG(duration) FROM (
                    SELECT %s AS duration
                    FROM analytics_events
                    WHERE site_id = :siteId AND event_type = :eventType AND occurred_at BETWEEN :start AND :end
                    GROUP BY session_id
                ) session_durations',
                $this->secondsBetweenExpr('MIN(occurred_at)', 'MAX(occurred_at)')
            ),
            [
                'siteId' => $siteId,
                'eventType' => 'pageview',
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]
        )->fetchOne();

        return (int) round((float) $avgSeconds);
    }

    private function formatDuration(int $seconds): string
    {
        return sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60);
    }

    private function countOnlineNow(string $siteId): int
    {
        $cutoff = (new \DateTimeImmutable(sprintf('-%d minutes', self::ONLINE_WINDOW_MINUTES)))->format('Y-m-d H:i:s');

        return (int) $this->connection->createQueryBuilder()
            ->select('COUNT(DISTINCT session_id)')
            ->from('analytics_events')
            ->where('site_id = :siteId')
            ->andWhere('occurred_at >= :cutoff')
            ->setParameter('siteId', $siteId)
            ->setParameter('cutoff', $cutoff)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array{labels: array<int, string>, datasets: array<int, array<string, mixed>>}
     */
    private function buildTimeSeries(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end, string $granularity): array
    {
        $rows = $this->analyticsQuery->baseQuery($siteId, $start, $end)
            ->select(sprintf('%s as bucket, COUNT(*) as count', $this->dateBucketExpr('occurred_at', $granularity)))
            ->groupBy('bucket')
            ->orderBy('bucket', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $byBucket = array_column($rows, 'count', 'bucket');

        $phpFormat = $granularity === 'hourly' ? 'Y-m-d H:00:00' : 'Y-m-d';
        $labelFormat = $granularity === 'hourly' ? 'H:00' : 'M j';
        $interval = new \DateInterval($granularity === 'hourly' ? 'PT1H' : 'P1D');

        $labels = [];
        $data = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $key = $cursor->format($phpFormat);
            $labels[] = $cursor->format($labelFormat);
            $data[] = (int) ($byBucket[$key] ?? 0);
            $cursor = $cursor->add($interval);
        }

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Visitors',
                'data' => $data,
                'borderColor' => '#4f46e5',
                'backgroundColor' => 'rgba(79, 70, 229, 0.1)',
                'fill' => true,
                'tension' => 0.3,
            ]],
        ];
    }

    /**
     * @return array{labels: array<int, string>, datasets: array<int, array<string, mixed>>}
     */
    private function buildChannelChartData(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $rows = $this->analyticsQuery->baseQuery($siteId, $start, $end)
            ->select('referrer, utm_source, utm_medium, COUNT(*) as count')
            ->groupBy('referrer, utm_source, utm_medium')
            ->executeQuery()
            ->fetchAllAssociative();

        $channels = [];
        foreach ($rows as $row) {
            $channel = $this->channelClassifier->classify($row['referrer'], $row['utm_source'], $row['utm_medium']);
            $channels[$channel] = ($channels[$channel] ?? 0) + (int) $row['count'];
        }

        arsort($channels);

        $palette = [
            'Direct' => '#94a3b8',
            'Organic Search' => '#4f46e5',
            'Organic Social' => '#22c55e',
            'Referral' => '#f59e0b',
        ];

        return [
            'labels' => array_keys($channels),
            'datasets' => [[
                'data' => array_values($channels),
                'backgroundColor' => array_map(static fn (string $label) => $palette[$label] ?? '#cbd5e1', array_keys($channels)),
            ]],
        ];
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    private function groupBy(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end, string $column): array
    {
        if (!in_array($column, self::GROUPABLE_COLUMNS, true)) {
            throw new \InvalidArgumentException(sprintf('Column "%s" is not groupable.', $column));
        }

        $rows = $this->analyticsQuery->baseQuery($siteId, $start, $end)
            ->select(sprintf('%s as label, COUNT(*) as count', $column))
            ->groupBy($column)
            ->orderBy('count', 'DESC')
            ->setMaxResults(10)
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as &$row) {
            $row['count'] = (int) $row['count'];
        }

        return $rows;
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    private function buildEntryPages(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $rows = $this->connection->executeQuery(
            "SELECT path as label, COUNT(*) as count FROM (
                SELECT path,
                       ROW_NUMBER() OVER (PARTITION BY session_id ORDER BY occurred_at ASC) as rn
                FROM analytics_events
                WHERE site_id = :siteId AND event_type = :eventType AND occurred_at BETWEEN :start AND :end
            ) ranked
            WHERE rn = 1
            GROUP BY path
            ORDER BY count DESC
            LIMIT 10",
            [
                'siteId' => $siteId,
                'eventType' => 'pageview',
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]
        )->fetchAllAssociative();

        foreach ($rows as &$row) {
            $row['count'] = (int) $row['count'];
        }

        return $rows;
    }
}
