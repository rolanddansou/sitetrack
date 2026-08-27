<?php

declare(strict_types=1);

namespace App\Domain\DTO;

class MonitorReportSummaryDto
{
    public function __construct(
        public readonly int $monitorId,
        public readonly string $monitorPublicId,
        public readonly string $monitorName,
        public readonly IncidentImpactReportDto $report
    ) {}
}
