<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Entity\Monitor;
use App\Domain\Repository\MonitorRepositoryInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

class CachedMonitorRepository implements MonitorRepositoryInterface
{
    public function __construct(
        private MonitorRepositoryInterface $delegate,
        private CacheItemPoolInterface $cache,
        private ?LoggerInterface $logger = null
    ) {}

    public function find(int $id): ?Monitor
    {
        $cacheKey = sprintf('monitor_%d', $id);

        try {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return $item->get();
            }

            $monitor = $this->delegate->find($id);
            if ($monitor !== null) {
                $item->set($monitor);
                $item->expiresAfter(300); // 5 minutes
                $this->cache->save($item);
            }
            return $monitor;
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->warning(sprintf('Cache failure in CachedMonitorRepository::find: %s. Falling back to database.', $e->getMessage()));
            }
            return $this->delegate->find($id);
        }
    }

    public function findActiveMonitors(): array
    {
        $cacheKey = 'active_monitors';

        try {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return $item->get();
            }

            $monitors = $this->delegate->findActiveMonitors();
            $item->set($monitors);
            $item->expiresAfter(60); // 1 minute
            $this->cache->save($item);
            return $monitors;
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->warning(sprintf('Cache failure in CachedMonitorRepository::findActiveMonitors: %s. Falling back to database.', $e->getMessage()));
            }
            return $this->delegate->findActiveMonitors();
        }
    }

    public function findActiveMonitorsByTenant(int $tenantId): array
    {
        $cacheKey = sprintf('active_monitors_tenant_%d', $tenantId);

        try {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return $item->get();
            }

            $monitors = $this->delegate->findActiveMonitorsByTenant($tenantId);
            $item->set($monitors);
            $item->expiresAfter(60); // 1 minute
            $this->cache->save($item);
            return $monitors;
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->warning(sprintf('Cache failure in CachedMonitorRepository::findActiveMonitorsByTenant: %s. Falling back to database.', $e->getMessage()));
            }
            return $this->delegate->findActiveMonitorsByTenant($tenantId);
        }
    }

    public function save(Monitor $monitor): void
    {
        $this->delegate->save($monitor);
        $this->clearCache($monitor);
    }

    public function delete(Monitor $monitor): void
    {
        $this->delegate->delete($monitor);
        $this->clearCache($monitor);
    }

    private function clearCache(Monitor $monitor): void
    {
        try {
            if ($monitor->getId() !== null) {
                $this->cache->deleteItem(sprintf('monitor_%d', $monitor->getId()));
            }
            $this->cache->deleteItem('active_monitors');
            $this->cache->deleteItem(sprintf('active_monitors_tenant_%d', $monitor->getTenantId()));
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->warning(sprintf('Cache clearing failure in CachedMonitorRepository: %s', $e->getMessage()));
            }
        }
    }
}
