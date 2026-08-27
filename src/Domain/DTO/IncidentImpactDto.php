<?php

declare(strict_types=1);

namespace App\Domain\DTO;

class IncidentImpactDto
{
    /**
     * @param array<int, array{label: string, count: int}> $topCountriesBaseline
     * @param array<int, array{label: string, count: int}> $topDevicesBaseline
     */
    public function __construct(
        public readonly int $id,
        public readonly string $conditionType,
        public readonly \DateTimeImmutable $triggeredAt,
        public readonly ?\DateTimeImmutable $resolvedAt,
        public readonly ?int $durationMinutes,
        public readonly int $pageviewsDuringIncident,
        public readonly int $expectedPageviewsBaseline,
        public readonly int $estimatedLostPageviews,
        public readonly array $topCountriesBaseline = [],
        public readonly array $topDevicesBaseline = []
    ) {}
}
