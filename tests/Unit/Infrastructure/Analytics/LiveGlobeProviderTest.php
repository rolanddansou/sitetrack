<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Analytics;

use App\Infrastructure\Analytics\AnalyticsQueryService;
use App\Infrastructure\Analytics\LiveGlobeProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class LiveGlobeProviderTest extends TestCase
{
    public function testGetPayloadReturnsCachedValueOnCacheHit(): void
    {
        $cachedPayload = ['online' => 3, 'onlineCountries' => 1, 'pins' => [], 'feed' => []];

        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($cachedPayload);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->with('live_globe_abc123')->willReturn($item);

        $analyticsQuery = $this->createMock(AnalyticsQueryService::class);
        $analyticsQuery->expects($this->never())->method('countOnlineNow');

        $provider = new LiveGlobeProvider($analyticsQuery, $cache);

        $this->assertSame($cachedPayload, $provider->getPayload('abc123'));
    }

    public function testGetPayloadFetchesAndCachesOnCacheMiss(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->expects($this->once())->method('set')->with($this->callback(
            static fn (array $payload): bool => $payload['online'] === 2
        ));
        $item->expects($this->once())->method('expiresAfter')->with(5);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->expects($this->once())->method('save')->with($item);

        $analyticsQuery = $this->createMock(AnalyticsQueryService::class);
        $analyticsQuery->method('countOnlineNow')->willReturn(2);
        $analyticsQuery->method('getOnlineCountryPins')->willReturn([['country' => 'FR', 'count' => 2]]);
        $analyticsQuery->method('getLiveFeed')->willReturn([]);

        $provider = new LiveGlobeProvider($analyticsQuery, $cache);
        $payload = $provider->getPayload('abc123');

        $this->assertSame(2, $payload['online']);
        $this->assertSame(1, $payload['onlineCountries']);
    }

    public function testGetPayloadFallsBackToDatabaseOnCacheFailure(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('cache backend unreachable'));

        $analyticsQuery = $this->createMock(AnalyticsQueryService::class);
        $analyticsQuery->method('countOnlineNow')->willReturn(1);
        $analyticsQuery->method('getOnlineCountryPins')->willReturn([['country' => 'US', 'count' => 1]]);
        $analyticsQuery->method('getLiveFeed')->willReturn([]);

        $provider = new LiveGlobeProvider($analyticsQuery, $cache);
        $payload = $provider->getPayload('abc123');

        $this->assertSame(1, $payload['online']);
        $this->assertSame(1, $payload['onlineCountries']);
    }
}
