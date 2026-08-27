<?php

declare(strict_types=1);

namespace App\Domain\DTO;

class LatencyTrafficPointDto
{
    public function __construct(
        public readonly string $date,
        public readonly ?float $avgLatencyMs,
        public readonly int $pageviews
    ) {}
}
