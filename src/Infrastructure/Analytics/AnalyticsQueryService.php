<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Shared pageview/visitor/bounce-rate queries against analytics_events —
 * used by both AnalyticsController (full dashboard) and
 * DashboardOverviewController (7-day recap), so the definition of what
 * counts as a "pageview" in scope stays in exactly one place.
 */
class AnalyticsQueryService
{
    /**
     * "Online now" is a recent-activity proxy. event.js sends a 'pageview'
     * on load/navigation and, while the tab stays visible, a 'heartbeat'
     * roughly every 20s — the same reason a page read for minutes without
     * clicking anything still counts as "online" in Google Analytics.
     */
    private const ONLINE_WINDOW_MINUTES = 5;

    /** Columns that {@see groupBy()} is allowed to select — never build this from request input. */
    private const GROUPABLE_COLUMNS = ['referrer', 'utm_campaign', 'path', 'country', 'region', 'city', 'browser', 'os', 'device'];

    public function __construct(private Connection $connection) {}

    /**
     * @return string[]
     */
    public function groupableColumns(): array
    {
        return self::GROUPABLE_COLUMNS;
    }

    /**
     * SQLite/MySQL/Postgres each spell "truncate a datetime to hour/day"
     * differently, and there's no portable Doctrine QueryBuilder helper for
     * it outside the ORM/DQL layer. Public (moved from AnalyticsController)
     * so any raw-DBAL bucketed query — analytics or otherwise — can reuse it
     * instead of re-deriving the same platform branching.
     */
    public function dateBucketExpr(string $column, string $granularity): string
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

    /**
     * Daily pageview counts, keyed by day (Y-m-d) — the pageview half of the
     * latency×traffic correlation (see LatencyTrafficService), and a
     * candidate for buildTimeSeries()-style charting wherever daily
     * granularity is all that's needed.
     *
     * @return array<string, int>
     */
    public function pageviewsByDay(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $rows = $this->baseQuery($siteId, $start, $end)
            ->select(sprintf('%s as bucket, COUNT(*) as count', $this->dateBucketExpr('occurred_at', 'daily')))
            ->groupBy('bucket')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['bucket']] = (int) $row['count'];
        }

        return $result;
    }

    public function baseQuery(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->from('analytics_events')
            ->where('site_id = :siteId')
            ->andWhere('event_type = :eventType')
            ->andWhere('occurred_at BETWEEN :start AND :end')
            ->setParameter('siteId', $siteId)
            ->setParameter('eventType', 'pageview')
            ->setParameter('start', $start->format('Y-m-d H:i:s'))
            ->setParameter('end', $end->format('Y-m-d H:i:s'));
    }

    public function countUniqueVisitors(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->baseQuery($siteId, $start, $end)
            ->select('COUNT(DISTINCT session_id)')
            ->executeQuery()
            ->fetchOne();
    }

    public function countPageviews(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int) $this->baseQuery($siteId, $start, $end)
            ->select('COUNT(*)')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Pass $totalVisitors when the caller already has it (from
     * countUniqueVisitors() for the same range) to avoid recomputing it.
     */
    public function calculateBounceRate(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $totalVisitors = null): float
    {
        $totalVisitors ??= $this->countUniqueVisitors($siteId, $start, $end);
        if ($totalVisitors === 0) {
            return 0.0;
        }

        $bouncedSessions = (int) $this->connection->executeQuery(
            'SELECT COUNT(*) FROM (
                SELECT session_id FROM analytics_events
                WHERE site_id = :siteId AND event_type = :eventType AND occurred_at BETWEEN :start AND :end
                GROUP BY session_id HAVING COUNT(*) = 1
            ) bounced_sessions',
            [
                'siteId' => $siteId,
                'eventType' => 'pageview',
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]
        )->fetchOne();

        return round($bouncedSessions / $totalVisitors * 100, 1);
    }

    public function countOnlineNow(string $siteId): int
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
     * @return array<int, array{label: string, count: int}>
     */
    public function groupBy(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end, string $column): array
    {
        if (!in_array($column, self::GROUPABLE_COLUMNS, true)) {
            throw new \InvalidArgumentException(sprintf('Column "%s" is not groupable.', $column));
        }

        $rows = $this->baseQuery($siteId, $start, $end)
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
     * Countries with at least one session active in the last {@see ONLINE_WINDOW_MINUTES}
     * minutes — pageview OR heartbeat, same as {@see countOnlineNow()}, so a pin doesn't
     * vanish off the globe while its visitor is still reading the page they landed on.
     *
     * @return array<int, array{country: ?string, count: int}>
     */
    public function getOnlineCountryPins(string $siteId): array
    {
        $cutoff = (new \DateTimeImmutable(sprintf('-%d minutes', self::ONLINE_WINDOW_MINUTES)))->format('Y-m-d H:i:s');

        $rows = $this->connection->createQueryBuilder()
            ->select('country, COUNT(DISTINCT session_id) as count')
            ->from('analytics_events')
            ->where('site_id = :siteId')
            ->andWhere('(event_type = :eventType OR event_type = :heartbeatType)')
            ->andWhere('occurred_at >= :cutoff')
            ->andWhere('country IS NOT NULL')
            ->groupBy('country')
            ->orderBy('count', 'DESC')
            ->setParameter('siteId', $siteId)
            ->setParameter('eventType', 'pageview')
            ->setParameter('heartbeatType', 'heartbeat')
            ->setParameter('cutoff', $cutoff)
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as &$row) {
            $row['count'] = (int) $row['count'];
        }

        return $rows;
    }

    /**
     * Most recent pageviews within the same {@see ONLINE_WINDOW_MINUTES} window
     * as {@see getOnlineCountryPins()} — this feeds the "who's online" strip, so
     * it must never show activity older than what "online" itself counts as.
     *
     * @return array<int, array{country: ?string, path: string, occurredAt: \DateTimeImmutable}>
     */
    public function getLiveFeed(string $siteId, int $limit = 8): array
    {
        $cutoff = (new \DateTimeImmutable(sprintf('-%d minutes', self::ONLINE_WINDOW_MINUTES)))->format('Y-m-d H:i:s');

        $rows = $this->connection->createQueryBuilder()
            ->select('country, path, occurred_at')
            ->from('analytics_events')
            ->where('site_id = :siteId')
            ->andWhere('event_type = :eventType')
            ->andWhere('occurred_at >= :cutoff')
            ->orderBy('occurred_at', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('siteId', $siteId)
            ->setParameter('eventType', 'pageview')
            ->setParameter('cutoff', $cutoff)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'country' => $row['country'],
            'path' => $row['path'],
            'occurredAt' => new \DateTimeImmutable($row['occurred_at']),
        ], $rows);
    }

    /**
     * Two-dimensional breakdown (e.g. "pages visited by country") — unlike
     * {@see groupBy()}, which groups on a single column. Returns raw combo
     * rows sorted by count, capped generously; callers pick the top rows/
     * columns to actually render as a matrix (this method doesn't shape a
     * pivot table itself, matching the "shared query, presentation stays in
     * the controller" split already used elsewhere in this class).
     *
     * @return array<int, array{labelA: ?string, labelB: ?string, count: int}>
     */
    public function crossTab(string $siteId, \DateTimeImmutable $start, \DateTimeImmutable $end, string $columnA, string $columnB): array
    {
        if (!in_array($columnA, self::GROUPABLE_COLUMNS, true) || !in_array($columnB, self::GROUPABLE_COLUMNS, true)) {
            throw new \InvalidArgumentException('Column not groupable.');
        }

        $rows = $this->baseQuery($siteId, $start, $end)
            ->select(sprintf('%s as labelA, %s as labelB, COUNT(*) as count', $columnA, $columnB))
            ->groupBy($columnA)
            ->addGroupBy($columnB)
            ->orderBy('count', 'DESC')
            ->setMaxResults(500)
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as &$row) {
            $row['count'] = (int) $row['count'];
        }

        return $rows;
    }

    /**
     * All-time pageview count and the timestamp of the earliest one on record
     * for this site — no date-range filter, unlike every other method here.
     *
     * @return array{total: int, since: ?\DateTimeImmutable}
     */
    public function getAllTimeVisits(string $siteId): array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('COUNT(*) as total, MIN(occurred_at) as since')
            ->from('analytics_events')
            ->where('site_id = :siteId')
            ->andWhere('event_type = :eventType')
            ->setParameter('siteId', $siteId)
            ->setParameter('eventType', 'pageview')
            ->executeQuery()
            ->fetchAssociative();

        return [
            'total' => (int) $row['total'],
            'since' => $row['since'] !== null ? new \DateTimeImmutable($row['since']) : null,
        ];
    }
}
