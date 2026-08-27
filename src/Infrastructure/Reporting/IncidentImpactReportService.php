<?php

declare(strict_types=1);

namespace App\Infrastructure\Reporting;

use App\Domain\DTO\IncidentImpactDto;
use App\Domain\DTO\IncidentImpactReportDto;
use App\Domain\Entity\Monitor;
use App\Domain\Entity\Workspace;
use App\Domain\Repository\CheckResultRepositoryInterface;
use App\Infrastructure\Analytics\AnalyticsQueryService;

/**
 * Cross-references a monitor's uptime/incident history with its workspace's
 * traffic to estimate the business impact of downtime — uptime %, MTTR, and
 * per-incident "expected vs actual" pageviews (baseline = the equal-length
 * window immediately preceding the incident, mirroring
 * AnalyticsController::resolvePreviousRange()).
 */
class IncidentImpactReportService
{
    public function __construct(
        private IncidentQueryService $incidentQuery,
        private CheckResultRepositoryInterface $checkResultRepository,
        private AnalyticsQueryService $analyticsQuery
    ) {}

    public function generateReport(Monitor $monitor, Workspace $workspace, \DateTimeImmutable $start, \DateTimeImmutable $end): IncidentImpactReportDto
    {
        $counts = $this->checkResultRepository->countByStatusInRange($monitor->getId(), $start, $end);
        $total = array_sum($counts);
        $uptimePercent = $total > 0 ? round($counts['up'] / $total * 100, 2) : 100.0;

        $rows = $this->incidentQuery->findIncidentsInRange($monitor->getId(), $start, $end);
        $siteId = $workspace->getSiteId();
        $now = new \DateTimeImmutable();

        $incidents = [];
        $durationsForMttr = [];
        $totalLost = 0;

        foreach ($rows as $row) {
            $triggeredAt = new \DateTimeImmutable($row['triggered_at']);
            $resolvedAt = $row['resolved_at'] !== null ? new \DateTimeImmutable($row['resolved_at']) : null;
            $incidentEnd = $resolvedAt ?? min($end, $now);

            $durationMinutes = null;
            if ($resolvedAt !== null) {
                $durationMinutes = (int) round(($resolvedAt->getTimestamp() - $triggeredAt->getTimestamp()) / 60);
                $durationsForMttr[] = $durationMinutes;
            }

            $actual = $this->analyticsQuery->countPageviews($siteId, $triggeredAt, $incidentEnd);

            $windowSeconds = max($incidentEnd->getTimestamp() - $triggeredAt->getTimestamp(), 60);
            $baselineStart = $triggeredAt->modify(sprintf('-%d seconds', $windowSeconds));
            $baseline = $this->analyticsQuery->countPageviews($siteId, $baselineStart, $triggeredAt);

            $lost = max(0, $baseline - $actual);
            $totalLost += $lost;

            // "Who would likely have been most affected" — derived from the
            // baseline window's traffic profile (the incident window itself
            // has too little/no traffic to profile meaningfully by
            // definition, and we can't observe visitors who never got through).
            $topCountries = array_slice($this->analyticsQuery->groupBy($siteId, $baselineStart, $triggeredAt, 'country'), 0, 3);
            $topDevices = array_slice($this->analyticsQuery->groupBy($siteId, $baselineStart, $triggeredAt, 'device'), 0, 3);

            $incidents[] = new IncidentImpactDto(
                id: (int) $row['id'],
                conditionType: (string) $row['condition_type'],
                triggeredAt: $triggeredAt,
                resolvedAt: $resolvedAt,
                durationMinutes: $durationMinutes,
                pageviewsDuringIncident: $actual,
                expectedPageviewsBaseline: $baseline,
                estimatedLostPageviews: $lost,
                topCountriesBaseline: $topCountries,
                topDevicesBaseline: $topDevices
            );
        }

        $mttrMinutes = $durationsForMttr !== [] ? (int) round(array_sum($durationsForMttr) / count($durationsForMttr)) : null;

        return new IncidentImpactReportDto(
            rangeStart: $start,
            rangeEnd: $end,
            uptimePercent: $uptimePercent,
            mttrMinutes: $mttrMinutes,
            incidentCount: count($incidents),
            totalEstimatedLostPageviews: $totalLost,
            incidents: $incidents
        );
    }
}
