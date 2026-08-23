<?php

declare(strict_types=1);

namespace App\Domain\Service;

interface GeoIpResolverInterface
{
    /**
     * @return array{country: ?string, region: ?string, city: ?string}
     */
    public function resolve(?string $ip): array;
}
