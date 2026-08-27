<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Reporting;

use App\Domain\DTO\IncidentImpactDto;
use App\Domain\DTO\IncidentImpactReportDto;
use App\Domain\Entity\Monitor;
use App\Domain\Entity\Workspace;
use App\Domain\Repository\CheckResultRepositoryInterface;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Infrastructure\Reporting\IncidentImpactReportService;
use App\Infrastructure\Reporting\WorkspaceReportService;
use PHPUnit\Framework\TestCase;

class WorkspaceReportServiceTest extends TestCase
{
    public function testGenerateReportAggregatesFromFlattenedPerMonitorData(): void
    {
        $workspace = (new Workspace(1, 'Test Workspace'))->setId(5);
        $monitorA = (new Monitor($workspace->getId(), 'Monitor A', 'http', 'https://a.test', 5))->setId(1);
        $monitorB = (new Monitor($workspace->getId(), 'Monitor B', 'http', 'https://b.test', 5))->setId(2);

        $start = new \DateTimeImmutable('2026-01-01 00:00:00');
        $end = new \DateTimeImmutable('2026-01-08 00:00:00');

        $monitorRepo = $this->createMock(MonitorRepositoryInterface::class);
        $monitorRepo->method('findActiveMonitorsByWorkspace')->willReturn([$monitorA, $monitorB]);

        $checkResultRepo = $this->createMock(CheckResultRepositoryInterface::class);
        $checkResultRepo->method('countByStatusInRange')->willReturnMap([
            [1, $start, $end, ['up' => 8, 'down' => 2, 'timeout' => 0]],
            [2, $start, $end, ['up' => 10, 'down' => 0, 'timeout' => 0]],
        ]);

        $incidentA = new IncidentImpactDto(
            id: 1,
            conditionType: 'down_count',
            triggeredAt: new \DateTimeImmutable('2026-01-02 10:00:00'),
            resolvedAt: new \DateTimeImmutable('2026-01-02 10:10:00'),
            durationMinutes: 10,
            pageviewsDuringIncident: 1,
            expectedPageviewsBaseline: 5,
            estimatedLostPageviews: 4
        );
        $reportA = new IncidentImpactReportDto($start, $end, 80.0, 10, 1, 4, [$incidentA]);
        $reportB = new IncidentImpactReportDto($start, $end, 100.0, null, 0, 0, []);

        $incidentImpactService = $this->createMock(IncidentImpactReportService::class);
        $incidentImpactService->method('generateReport')->willReturnMap([
            [$monitorA, $workspace, $start, $end, $reportA],
            [$monitorB, $workspace, $start, $end, $reportB],
        ]);

        $service = new WorkspaceReportService($monitorRepo, $checkResultRepo, $incidentImpactService);
        $report = $service->generateReport($workspace, $start, $end);

        // (8 + 10) up out of (10 + 10) checks = 90%.
        $this->assertSame(90.0, $report->uptimePercent);
        $this->assertSame(10, $report->mttrMinutes);
        $this->assertSame(1, $report->incidentCount);
        $this->assertSame(4, $report->totalEstimatedLostPageviews);
        $this->assertCount(2, $report->monitorReports);
        $this->assertSame('Monitor A', $report->monitorReports[0]->monitorName);
        $this->assertSame('Monitor B', $report->monitorReports[1]->monitorName);
    }

    public function testGenerateReportReturnsFullUptimeWithNoMonitors(): void
    {
        $workspace = (new Workspace(1, 'Empty Workspace'))->setId(6);
        $start = new \DateTimeImmutable('2026-01-01 00:00:00');
        $end = new \DateTimeImmutable('2026-01-08 00:00:00');

        $monitorRepo = $this->createMock(MonitorRepositoryInterface::class);
        $monitorRepo->method('findActiveMonitorsByWorkspace')->willReturn([]);

        $checkResultRepo = $this->createMock(CheckResultRepositoryInterface::class);
        $incidentImpactService = $this->createMock(IncidentImpactReportService::class);
        $incidentImpactService->expects($this->never())->method('generateReport');

        $service = new WorkspaceReportService($monitorRepo, $checkResultRepo, $incidentImpactService);
        $report = $service->generateReport($workspace, $start, $end);

        $this->assertSame(100.0, $report->uptimePercent);
        $this->assertNull($report->mttrMinutes);
        $this->assertSame(0, $report->incidentCount);
        $this->assertSame([], $report->monitorReports);
    }
}
