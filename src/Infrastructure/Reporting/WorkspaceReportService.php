<?php

declare(strict_types=1);

namespace App\Infrastructure\Reporting;

use App\Domain\DTO\MonitorReportSummaryDto;
use App\Domain\DTO\WorkspaceReportDto;
use App\Domain\Entity\Workspace;
use App\Domain\Repository\CheckResultRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;

/**
 * Aggregates IncidentImpactReportService across every active monitor in a
 * workspace — cross-monitor comparison (A3) plus a workspace-wide uptime/
 * MTTR/lost-pageviews rollup (A1). The rollup is computed from the flattened
 * per-monitor data (all incidents combined, raw up/total check counts
 * summed) rather than averaging each monitor's own percentages, so it stays
 * correct regardless of how unevenly checks/incidents are distributed
 * across monitors.
 */
class WorkspaceReportService
{
    public function __construct(
        private MonitorRepositoryInterface $monitorRepository,
        private CheckResultRepositoryInterface $checkResultRepository,
        private IncidentImpactReportService $incidentImpactReportService
    ) {}

    public function generateReport(Workspace $workspace, \DateTimeImmutable $start, \DateTimeImmutable $end): WorkspaceReportDto
    {
        $monitors = $this->monitorRepository->findActiveMonitorsByWorkspace($workspace->getId());

        $monitorReports = [];
        $totalUp = 0;
        $totalChecks = 0;
        $totalIncidents = 0;
        $totalLost = 0;
        $allResolvedDurations = [];

        foreach ($monitors as $monitor) {
            $report = $this->incidentImpactReportService->generateReport($monitor, $workspace, $start, $end);

            $counts = $this->checkResultRepository->countByStatusInRange($monitor->getId(), $start, $end);
            $totalUp += $counts['up'];
            $totalChecks += array_sum($counts);
            $totalIncidents += $report->incidentCount;
            $totalLost += $report->totalEstimatedLostPageviews;

            foreach ($report->incidents as $incident) {
                if ($incident->durationMinutes !== null) {
                    $allResolvedDurations[] = $incident->durationMinutes;
                }
            }

            $monitorReports[] = new MonitorReportSummaryDto(
                monitorId: $monitor->getId(),
                monitorPublicId: $monitor->getPublicId(),
                monitorName: $monitor->getName(),
                report: $report
            );
        }

        $uptimePercent = $totalChecks > 0 ? round($totalUp / $totalChecks * 100, 2) : 100.0;
        $mttrMinutes = $allResolvedDurations !== [] ? (int) round(array_sum($allResolvedDurations) / count($allResolvedDurations)) : null;

        return new WorkspaceReportDto(
            rangeStart: $start,
            rangeEnd: $end,
            uptimePercent: $uptimePercent,
            mttrMinutes: $mttrMinutes,
            incidentCount: $totalIncidents,
            totalEstimatedLostPageviews: $totalLost,
            monitorReports: $monitorReports
        );
    }
}
