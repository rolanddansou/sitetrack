<?php

declare(strict_types=1);

namespace App\Infrastructure\GeoIp;

use App\Domain\Service\GeoIpResolverInterface;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;

/**
 * Resolves an IP to country/region/city using a local MaxMind GeoLite2-City
 * database (mmdb). Path is configured via GEOIP_DB_PATH; if empty or the
 * file doesn't exist, lookups are silently skipped (empty result).
 */
class MaxMindGeoIpResolver implements GeoIpResolverInterface
{
    private ?Reader $reader = null;
    private bool $available;

    public function __construct(private readonly string $dbPath)
    {
        $this->available = $dbPath !== '' && is_file($dbPath);
    }

    public function resolve(?string $ip): array
    {
        $empty = ['country' => null, 'region' => null, 'city' => null];

        if (!$this->available || $ip === null || $ip === '' || $this->isPrivateIp($ip)) {
            return $empty;
        }

        try {
            $record = $this->getReader()->city($ip);

            return [
                'country' => $this->normalise($record->country->isoCode),
                'region' => $this->normalise($record->mostSpecificSubdivision->isoCode),
                'city' => $this->normalise($record->city->name),
            ];
        } catch (AddressNotFoundException) {
            return $empty;
        } catch (\Throwable) {
            return $empty;
        }
    }

    private function getReader(): Reader
    {
        if ($this->reader === null) {
            $this->reader = new Reader($this->dbPath);
        }

        return $this->reader;
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private function normalise(?string $value): ?string
    {
        return ($value !== null && $value !== '') ? $value : null;
    }

    public function __destruct()
    {
        $this->reader?->close();
    }
}
