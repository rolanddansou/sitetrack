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
}
