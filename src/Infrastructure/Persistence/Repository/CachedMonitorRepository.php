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

    public function findByPublicId(string $publicId): ?Monitor
    {
        $cacheKey = sprintf('monitor_public_%s', $publicId);

        try {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return $item->get();
            }

            $monitor = $this->delegate->findByPublicId($publicId);
            if ($monitor !== null) {
                $item->set($monitor);
                $item->expiresAfter(300); // 5 minutes
                $this->cache->save($item);
            }
            return $monitor;
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->warning(sprintf('Cache failure in CachedMonitorRepository::findByPublicId: %s. Falling back to database.', $e->getMessage()));
            }
            return $this->delegate->findByPublicId($publicId);
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

    public function findActiveMonitorsByWorkspace(int $workspaceId): array
    {
        $cacheKey = sprintf('active_monitors_workspace_%d', $workspaceId);

        try {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return $item->get();
            }

            $monitors = $this->delegate->findActiveMonitorsByWorkspace($workspaceId);
            $item->set($monitors);
            $item->expiresAfter(60); // 1 minute
            $this->cache->save($item);
            return $monitors;
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->warning(sprintf('Cache failure in CachedMonitorRepository::findActiveMonitorsByWorkspace: %s. Falling back to database.', $e->getMessage()));
            }
            return $this->delegate->findActiveMonitorsByWorkspace($workspaceId);
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
            $this->cache->deleteItem(sprintf('monitor_public_%s', $monitor->getPublicId()));
            $this->cache->deleteItem('active_monitors');
            $this->cache->deleteItem(sprintf('active_monitors_workspace_%d', $monitor->getWorkspaceId()));
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->warning(sprintf('Cache clearing failure in CachedMonitorRepository: %s', $e->getMessage()));
            }
        }
    }
}
