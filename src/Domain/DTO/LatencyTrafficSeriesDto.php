<?php

declare(strict_types=1);

namespace App\Domain\DTO;

class LatencyTrafficSeriesDto
{
    /**
     * @param LatencyTrafficPointDto[] $points
     */
    public function __construct(
        public readonly array $points,
        public readonly ?float $correlation
    ) {}
}
