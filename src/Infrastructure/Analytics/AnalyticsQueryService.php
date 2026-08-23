<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Shared pageview/visitor/bounce-rate queries against analytics_events —
 * used by both AnalyticsController (full dashboard) and
 * DashboardOverviewController (7-day recap), so the definition of what
 * counts as a "pageview" in scope stays in exactly one place.
 */
class AnalyticsQueryService
{
    /** "Online now" is a recent-activity proxy, not a real-time heartbeat — event.js sends nothing while a visitor is idle on a page. */
    private const ONLINE_WINDOW_MINUTES = 5;

    /** Columns that {@see groupBy()} is allowed to select — never build this from request input. */
    private const GROUPABLE_COLUMNS = ['referrer', 'utm_campaign', 'path', 'country', 'region', 'city', 'browser', 'os', 'device'];

    public function __construct(private Connection $connection) {}

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
     * minutes — pageview-scoped deliberately (unlike {@see countOnlineNow()}, which has
     * never filtered by event_type): a country pin only makes sense for a page visit.
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
            ->andWhere('event_type = :eventType')
            ->andWhere('occurred_at >= :cutoff')
            ->andWhere('country IS NOT NULL')
            ->groupBy('country')
            ->orderBy('count', 'DESC')
            ->setParameter('siteId', $siteId)
            ->setParameter('eventType', 'pageview')
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
