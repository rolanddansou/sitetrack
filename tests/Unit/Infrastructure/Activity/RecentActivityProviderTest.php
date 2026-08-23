<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Activity;

use App\Infrastructure\Activity\RecentActivityProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class RecentActivityProviderTest extends TestCase
{
    public function testGetRecentActivityReturnsCachedValueOnCacheHit(): void
    {
        $cachedActivity = [['monitorName' => 'API', 'monitorPublicId' => 'uuid-1', 'status' => 'triggered', 'occurredAt' => new \DateTimeImmutable()]];

        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($cachedActivity);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->with('recent_activity_tenant_42')->willReturn($item);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeQuery');

        $provider = new RecentActivityProvider($connection, $cache);

        $this->assertSame($cachedActivity, $provider->getRecentActivity(42));
    }

    public function testGetRecentActivityFetchesAndCachesOnCacheMiss(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->expects($this->once())->method('set')->with($this->callback(
            static fn (array $activity): bool => count($activity) === 1 && $activity[0]['monitorName'] === 'API'
        ));
        $item->expects($this->once())->method('expiresAfter')->with(30);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->expects($this->once())->method('save')->with($item);

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([[
            'monitor_name' => 'API',
            'monitor_public_id' => 'uuid-1',
            'status' => 'triggered',
            'occurred_at' => '2026-08-23 10:00:00',
        ]]);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeQuery')->willReturn($result);

        $provider = new RecentActivityProvider($connection, $cache);
        $activity = $provider->getRecentActivity(42);

        $this->assertCount(1, $activity);
        $this->assertSame('API', $activity[0]['monitorName']);
        $this->assertSame('triggered', $activity[0]['status']);
    }

    public function testGetRecentActivityFallsBackToDatabaseOnCacheFailure(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('cache backend unreachable'));

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([[
            'monitor_name' => 'API',
            'monitor_public_id' => 'uuid-1',
            'status' => 'resolved',
            'occurred_at' => '2026-08-23 10:00:00',
        ]]);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);

        $provider = new RecentActivityProvider($connection, $cache);
        $activity = $provider->getRecentActivity(42);

        $this->assertCount(1, $activity);
        $this->assertSame('resolved', $activity[0]['status']);
    }
}
