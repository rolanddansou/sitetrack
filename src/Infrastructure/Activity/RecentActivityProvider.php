<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Feeds the dashboard's "recent activity" list — monitor status changes
 * (alert triggered/resolved), most recent first, across every monitor a
 * tenant owns.
 *
 * Cache-aside with a short TTL: there's no write path through this class to
 * hook a cache-clear into (alert_events is written by the check use cases,
 * not here), so a bit of staleness is the accepted trade-off — same idea as
 * the "online now" 5-minute window, just tighter since this is cheaper to
 * keep fresh. Falls back to a direct read on any cache failure, same shape
 * as CachedMonitorRepository.
 */
class RecentActivityProvider
{
    private const CACHE_TTL_SECONDS = 30;
    private const LIMIT = 10;

    public function __construct(
        private Connection $connection,
        private CacheItemPoolInterface $cache,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * @return array<int, array{monitorName: string, monitorPublicId: string, status: string, occurredAt: \DateTimeImmutable}>
     */
    public function getRecentActivity(int $tenantId): array
    {
        $cacheKey = sprintf('recent_activity_tenant_%d', $tenantId);

        try {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return $item->get();
            }

            $activity = $this->fetchFromDatabase($tenantId);
            $item->set($activity);
            $item->expiresAfter(self::CACHE_TTL_SECONDS);
            $this->cache->save($item);

            return $activity;
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->warning(sprintf('Cache failure in RecentActivityProvider::getRecentActivity: %s. Falling back to database.', $e->getMessage()));
            }
            return $this->fetchFromDatabase($tenantId);
        }
    }

    /**
     * @return array<int, array{monitorName: string, monitorPublicId: string, status: string, occurredAt: \DateTimeImmutable}>
     */
    private function fetchFromDatabase(int $tenantId): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT m.name AS monitor_name, m.public_id AS monitor_public_id, e.status,
                    COALESCE(e.resolved_at, e.triggered_at) AS occurred_at
             FROM alert_events e
             JOIN alert_rules r ON e.rule_id = r.id
             JOIN monitors m ON r.monitor_id = m.id
             WHERE m.tenant_id = :tenantId
             ORDER BY occurred_at DESC
             LIMIT ' . self::LIMIT,
            ['tenantId' => $tenantId]
        )->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'monitorName' => $row['monitor_name'],
            'monitorPublicId' => $row['monitor_public_id'],
            'status' => $row['status'],
            'occurredAt' => new \DateTimeImmutable($row['occurred_at']),
        ], $rows);
    }
}
