<?php

declare(strict_types=1);

namespace App\Domain\DTO;

class WorkspaceReportDto
{
    /**
     * @param MonitorReportSummaryDto[] $monitorReports
     */
    public function __construct(
        public readonly \DateTimeImmutable $rangeStart,
        public readonly \DateTimeImmutable $rangeEnd,
        public readonly float $uptimePercent,
        public readonly ?int $mttrMinutes,
        public readonly int $incidentCount,
        public readonly int $totalEstimatedLostPageviews,
        public readonly array $monitorReports
    ) {}
}
