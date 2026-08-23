<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Feeds the dashboard's "who's online" panel (online count, country pins,
 * live pageview feed) — cache-aside with a TTL matching the front-end's 5s
 * poll interval, so repeated /dashboard page loads (e.g. Turbo navigations
 * back to it) don't each pay for 3 fresh queries on top of the poll itself.
 * Falls back to a direct read on any cache failure, same shape as
 * CachedMonitorRepository/RecentActivityProvider. Unlike RecentActivityProvider's
 * 30s TTL, this one is deliberately as short as the poll cadence — any longer
 * and the poll would feel stale rather than live.
 */
class LiveGlobeProvider
{
    private const CACHE_TTL_SECONDS = 5;

    public function __construct(
        private AnalyticsQueryService $analyticsQuery,
        private CacheItemPoolInterface $cache,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * @return array{online: int, onlineCountries: int, pins: array<int, array{country: ?string, count: int}>, feed: array<int, array{country: ?string, path: string, occurredAt: \DateTimeImmutable}>}
     */
    public function getPayload(string $siteId): array
    {
        $cacheKey = sprintf('live_globe_%s', $siteId);

        try {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return $item->get();
            }

            $payload = $this->buildPayload($siteId);
            $item->set($payload);
            $item->expiresAfter(self::CACHE_TTL_SECONDS);
            $this->cache->save($item);

            return $payload;
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->warning(sprintf('Cache failure in LiveGlobeProvider::getPayload: %s. Falling back to database.', $e->getMessage()));
            }
            return $this->buildPayload($siteId);
        }
    }

    /**
     * @return array{online: int, onlineCountries: int, pins: array<int, array{country: ?string, count: int}>, feed: array<int, array{country: ?string, path: string, occurredAt: \DateTimeImmutable}>}
     */
    private function buildPayload(string $siteId): array
    {
        $pins = $this->analyticsQuery->getOnlineCountryPins($siteId);

        return [
            'online' => $this->analyticsQuery->countOnlineNow($siteId),
            'onlineCountries' => count($pins),
            'pins' => $pins,
            'feed' => $this->analyticsQuery->getLiveFeed($siteId),
        ];
    }
}
