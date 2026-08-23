<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\GeoIp;

use App\Infrastructure\GeoIp\MaxMindGeoIpResolver;
use PHPUnit\Framework\TestCase;

class MaxMindGeoIpResolverTest extends TestCase
{
    private const REAL_DB_PATH = __DIR__ . '/../../../../var/geoip/GeoLite2-City.mmdb';

    public function testReturnsEmptyResultWhenDbPathIsEmpty(): void
    {
        $resolver = new MaxMindGeoIpResolver('');

        $result = $resolver->resolve('8.8.8.8');

        $this->assertSame(['country' => null, 'region' => null, 'city' => null], $result);
    }

    public function testReturnsEmptyResultWhenDbFileDoesNotExist(): void
    {
        $resolver = new MaxMindGeoIpResolver('/nonexistent/path/GeoLite2-City.mmdb');

        $result = $resolver->resolve('8.8.8.8');

        $this->assertSame(['country' => null, 'region' => null, 'city' => null], $result);
    }

    public function testReturnsEmptyResultForNullOrEmptyIp(): void
    {
        $resolver = new MaxMindGeoIpResolver('');

        $this->assertSame(['country' => null, 'region' => null, 'city' => null], $resolver->resolve(null));
        $this->assertSame(['country' => null, 'region' => null, 'city' => null], $resolver->resolve(''));
    }

    public function testReturnsEmptyResultForPrivateIp(): void
    {
        $resolver = $this->realResolverOrSkip();

        $this->assertSame(['country' => null, 'region' => null, 'city' => null], $resolver->resolve('192.168.1.1'));
        $this->assertSame(['country' => null, 'region' => null, 'city' => null], $resolver->resolve('127.0.0.1'));
    }

    public function testReturnsEmptyResultForReservedTestNetIp(): void
    {
        $resolver = $this->realResolverOrSkip();

        // TEST-NET-3, reserved for documentation — never present in a real GeoIP database.
        $this->assertSame(['country' => null, 'region' => null, 'city' => null], $resolver->resolve('203.0.113.1'));
    }

    public function testResolvesCountryRegionAndCityForAKnownResidentialIp(): void
    {
        $resolver = $this->realResolverOrSkip();

        $result = $resolver->resolve('98.14.10.1');

        $this->assertSame('US', $result['country']);
        $this->assertSame('NY', $result['region']);
        $this->assertNotNull($result['city']);
    }

    private function realResolverOrSkip(): MaxMindGeoIpResolver
    {
        if (!is_file(self::REAL_DB_PATH)) {
            self::markTestSkipped('No GeoLite2-City.mmdb found at var/geoip/ — see CLAUDE.md for where to place it.');
        }

        return new MaxMindGeoIpResolver(self::REAL_DB_PATH);
    }
}
