<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\Entity\Workspace;
use App\Domain\Repository\WorkspaceRepositoryInterface;
use App\Infrastructure\Analytics\AnalyticsIconResolver;
use App\Infrastructure\Analytics\AnalyticsQueryService;
use App\Infrastructure\Analytics\ChannelClassifier;
use App\Infrastructure\Analytics\CountryNameResolver;
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
    private const CROSSTAB_AXIS_LIMIT = 8;

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

    public function __construct(
        private WorkspaceRepositoryInterface $workspaceRepository,
        private Connection $connection,
        private ChannelClassifier $channelClassifier,
        private AnalyticsQueryService $analyticsQuery,
        private AnalyticsIconResolver $iconResolver,
        private CountryNameResolver $countryNameResolver
    ) {}

    #[Route('/workspace/{workspacePublicId}/analytics', name: 'workspace_analytics_index', methods: ['GET'])]
    public function index(Workspace $workspace, Request $request): Response
    {
        $siteId = $workspace->getSiteId();

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
            'workspace' => $workspace,
            'workspaces' => $this->workspaceRepository->findByTenant($workspace->getTenantId()),
            'siteId' => $siteId,
            'period' => $period,
            'granularity' => $granularity,
            'uniqueVisitors' => $uniqueVisitors,
            'pageviews' => $pageviews,
            'bounceRate' => $bounceRate,
            'avgSessionDuration' => $this->formatDuration($sessionDurationSeconds),
            'onlineNow' => $this->analyticsQuery->countOnlineNow($siteId),
            'visitorsDelta' => $this->calculateDelta($uniqueVisitors, $prevUniqueVisitors),
            'pageviewsDelta' => $this->calculateDelta($pageviews, $this->analyticsQuery->countPageviews($siteId, $prevStart, $prevEnd)),
            'bounceRateDelta' => $this->calculateDelta($bounceRate, $this->analyticsQuery->calculateBounceRate($siteId, $prevStart, $prevEnd, $prevUniqueVisitors), lowerIsBetter: true),
            'sessionTimeDelta' => $this->calculateDelta($sessionDurationSeconds, $this->calculateAvgSessionDuration($siteId, $prevStart, $prevEnd)),
            'chartData' => $this->buildTimeSeries($siteId, $start, $end, $granularity),
            'channels' => $this->buildChannelChartData($siteId, $start, $end),
            'topReferrers' => $this->analyticsQuery->groupBy($siteId, $start, $end, 'referrer'),
            'topCampaigns' => $this->analyticsQuery->groupBy($siteId, $start, $end, 'utm_campaign'),
            'topPages' => $this->analyticsQuery->groupBy($siteId, $start, $end, 'path'),
            'entryPages' => $this->buildEntryPages($siteId, $start, $end),
            'countries' => $this->attachIcons($this->analyticsQuery->groupBy($siteId, $start, $end, 'country'), fn (?string $v) => $this->iconResolver->resolveCountryIcon($v)),
            'regions' => $this->analyticsQuery->groupBy($siteId, $start, $end, 'region'),
            'cities' => $this->analyticsQuery->groupBy($siteId, $start, $end, 'city'),
            'browsers' => $this->attachIcons($this->analyticsQuery->groupBy($siteId, $start, $end, 'browser'), fn (?string $v) => $this->iconResolver->resolveBrowserIcon($v)),
            'oses' => $this->attachIcons($this->analyticsQuery->groupBy($siteId, $start, $end, 'os'), fn (?string $v) => $this->iconResolver->resolveOsIcon($v)),
            'devices' => $this->attachIcons($this->analyticsQuery->groupBy($siteId, $start, $end, 'device'), fn (?string $v) => $this->iconResolver->resolveDeviceIcon((string) $v)),
        ]);
    }

    #[Route('/workspace/{workspacePublicId}/analytics/online-now', name: 'workspace_analytics_online_now', methods: ['GET'])]
    public function onlineNow(Workspace $workspace): JsonResponse
    {
        return new JsonResponse(['count' => $this->analyticsQuery->countOnlineNow($workspace->getSiteId())]);
    }

    /**
     * Generic two-dimension cross-tab (e.g. "pages visited by country"),
     * independent of any monitor/incident — unlike ReportController, which
     * only ever crosses traffic against downtime.
     */
    #[Route('/workspace/{workspacePublicId}/analytics/crosstab', name: 'workspace_analytics_crosstab', methods: ['GET'])]
    public function crosstab(Workspace $workspace, Request $request): Response
    {
        $siteId = $workspace->getSiteId();
        $period = $this->resolvePeriod((string) $request->query->get('period', ''));
        [$start, $end] = $this->resolveRange($period);

        $dimensions = $this->analyticsQuery->groupableColumns();
        $dimensionA = $this->resolveDimension((string) $request->query->get('dimensionA', ''), $dimensions, 'path');
        $dimensionB = $this->resolveDimension((string) $request->query->get('dimensionB', ''), $dimensions, 'country');

        $rows = $this->analyticsQuery->crossTab($siteId, $start, $end, $dimensionA, $dimensionB);
        $pivot = $this->buildCrossTabPivot($rows);

        return $this->render('dashboard/analytics_crosstab.html.twig', [
            'workspace' => $workspace,
            'workspaces' => $this->workspaceRepository->findByTenant($workspace->getTenantId()),
            'period' => $period,
            'dimensions' => $dimensions,
            'dimensionA' => $dimensionA,
            'dimensionB' => $dimensionB,
            'pivot' => $pivot,
            'rowDisplayLabels' => $this->resolveDisplayLabels($pivot['rowLabels'], $dimensionA),
            'colDisplayLabels' => $this->resolveDisplayLabels($pivot['colLabels'], $dimensionB),
        ]);
    }

    /**
     * Cross-tab axis labels are raw dimension values used as matrix keys
     * (e.g. "BJ") — resolved only for display, so a country axis reads as
     * "Bénin" the same way the live globe/breakdown does, without touching
     * the keys the matrix lookup depends on.
     *
     * @param string[] $labels
     * @return array<string, string>
     */
    private function resolveDisplayLabels(array $labels, string $dimension): array
    {
        if ($dimension !== 'country') {
            return array_combine($labels, $labels);
        }

        return array_combine($labels, array_map(
            fn (string $label): string => $this->countryNameResolver->resolve($label),
            $labels
        ));
    }

    /**
     * @param string[] $allowed
     */
    private function resolveDimension(string $dimension, array $allowed, string $default): string
    {
        return in_array($dimension, $allowed, true) ? $dimension : $default;
    }

    /**
     * Builds a matrix from the top N row-values × top N column-values found
     * in the (larger) raw combo list — row/column totals come from the same
     * result set rather than a separate query, so a value that's "top 8" by
     * cross-tab volume but not top 8 overall can't silently appear as a row
     * while missing as a column (or vice versa).
     *
     * @param array<int, array{labelA: ?string, labelB: ?string, count: int}> $rows
     * @return array{rowLabels: array<int, string>, colLabels: array<int, string>, matrix: array<string, array<string, int>>, total: int}
     */
    private function buildCrossTabPivot(array $rows): array
    {
        $rowTotals = [];
        $colTotals = [];
        foreach ($rows as $row) {
            $a = $row['labelA'] ?? '(none)';
            $b = $row['labelB'] ?? '(none)';
            $rowTotals[$a] = ($rowTotals[$a] ?? 0) + $row['count'];
            $colTotals[$b] = ($colTotals[$b] ?? 0) + $row['count'];
        }

        arsort($rowTotals);
        arsort($colTotals);

        $rowLabels = array_slice(array_keys($rowTotals), 0, self::CROSSTAB_AXIS_LIMIT);
        $colLabels = array_slice(array_keys($colTotals), 0, self::CROSSTAB_AXIS_LIMIT);

        $matrix = [];
        foreach ($rowLabels as $rowLabel) {
            foreach ($colLabels as $colLabel) {
                $matrix[$rowLabel][$colLabel] = 0;
            }
        }

        $total = 0;
        foreach ($rows as $row) {
            $a = $row['labelA'] ?? '(none)';
            $b = $row['labelB'] ?? '(none)';
            $total += $row['count'];
            if (isset($matrix[$a][$b])) {
                $matrix[$a][$b] = $row['count'];
            }
        }

        return ['rowLabels' => $rowLabels, 'colLabels' => $colLabels, 'matrix' => $matrix, 'total' => $total];
    }

    /**
     * @param array<int, array{label: string, count: int}> $rows
     * @return array<int, array{label: string, count: int, icon: ?string}>
     */
    private function attachIcons(array $rows, \Closure $resolve): array
    {
        foreach ($rows as &$row) {
            $row['icon'] = $resolve($row['label']);
        }

        return $rows;
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

    /** Same portability problem as {@see AnalyticsQueryService::dateBucketExpr()}, for "seconds between two datetimes". */
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

    /**
     * @return array{labels: array<int, string>, datasets: array<int, array<string, mixed>>}
     */
    private function buildTimeSeries(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end, string $granularity): array
    {
        $rows = $this->analyticsQuery->baseQuery($siteId, $start, $end)
            ->select(sprintf('%s as bucket, COUNT(*) as count', $this->analyticsQuery->dateBucketExpr('occurred_at', $granularity)))
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
                'borderColor' => '#0E7C6B',
                'backgroundColor' => 'rgba(14, 124, 107, 0.1)',
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
            'Direct' => '#9AA69E',
            'Recherche organique' => '#0E7C6B',
            'Social organique' => '#4C8577',
            'Référent' => '#C15A2B',
        ];

        return [
            'labels' => array_keys($channels),
            'datasets' => [[
                'data' => array_values($channels),
                'backgroundColor' => array_map(static fn (string $label) => $palette[$label] ?? '#D7DDD5', array_keys($channels)),
            ]],
        ];
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
