<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Reporting;

use App\Domain\Entity\Monitor;
use App\Domain\Entity\Workspace;
use App\Domain\Repository\CheckResultRepositoryInterface;
use App\Infrastructure\Analytics\AnalyticsQueryService;
use App\Infrastructure\Reporting\IncidentImpactReportService;
use App\Infrastructure\Reporting\IncidentQueryService;
use PHPUnit\Framework\TestCase;

class IncidentImpactReportServiceTest extends TestCase
{
    public function testGenerateReportComputesUptimeMttrAndLostPageviews(): void
    {
        $monitor = (new Monitor(1, 'Test Site', 'http', 'https://test.com', 5))->setId(10);
        $workspace = new Workspace(1, 'Test Workspace');

        $start = new \DateTimeImmutable('2026-01-01 00:00:00');
        $end = new \DateTimeImmutable('2026-01-03 00:00:00');

        $checkResultRepo = $this->createMock(CheckResultRepositoryInterface::class);
        $checkResultRepo->method('countByStatusInRange')
            ->with(10, $start, $end)
            ->willReturn(['up' => 8, 'down' => 2, 'timeout' => 0]);

        $incidentQuery = $this->createMock(IncidentQueryService::class);
        $incidentQuery->method('findIncidentsInRange')->with(10, $start, $end)->willReturn([
            [
                'id' => 1,
                'condition_type' => 'down_count',
                'triggered_at' => '2026-01-01 10:00:00',
                'resolved_at' => '2026-01-01 10:30:00',
            ],
            [
                'id' => 2,
                'condition_type' => 'latency_threshold',
                'triggered_at' => '2026-01-02 08:00:00',
                'resolved_at' => null,
            ],
        ]);

        // Call order per incident (ascending triggered_at, as IncidentQueryService returns):
        // incident A: actual(10:00-10:30)=2, baseline(09:30-10:00)=10 -> lost=8
        // incident B (ongoing, capped at report end): actual(2026-01-02 08:00 - 2026-01-03 00:00)=5,
        // baseline (equal-length window before)=3 -> lost=max(0,3-5)=0
        $analyticsQuery = $this->createMock(AnalyticsQueryService::class);
        $analyticsQuery->method('countPageviews')->willReturnOnConsecutiveCalls(2, 10, 5, 3);

        $service = new IncidentImpactReportService($incidentQuery, $checkResultRepo, $analyticsQuery);
        $report = $service->generateReport($monitor, $workspace, $start, $end);

        $this->assertSame(80.0, $report->uptimePercent);
        $this->assertSame(30, $report->mttrMinutes);
        $this->assertSame(2, $report->incidentCount);
        $this->assertSame(8, $report->totalEstimatedLostPageviews);

        $this->assertSame(30, $report->incidents[0]->durationMinutes);
        $this->assertSame(8, $report->incidents[0]->estimatedLostPageviews);

        $this->assertNull($report->incidents[1]->durationMinutes);
        $this->assertSame(0, $report->incidents[1]->estimatedLostPageviews);
    }

    public function testGenerateReportComputesTopCountryAndDeviceBreakdownFromBaselineWindow(): void
    {
        $monitor = (new Monitor(1, 'Test Site', 'http', 'https://test.com', 5))->setId(10);
        $workspace = new Workspace(1, 'Test Workspace');

        $start = new \DateTimeImmutable('2026-01-01 00:00:00');
        $end = new \DateTimeImmutable('2026-01-03 00:00:00');

        $checkResultRepo = $this->createMock(CheckResultRepositoryInterface::class);
        $checkResultRepo->method('countByStatusInRange')->willReturn(['up' => 1, 'down' => 0, 'timeout' => 0]);

        $incidentQuery = $this->createMock(IncidentQueryService::class);
        $incidentQuery->method('findIncidentsInRange')->willReturn([[
            'id' => 1,
            'condition_type' => 'down_count',
            'triggered_at' => '2026-01-01 10:00:00',
            'resolved_at' => '2026-01-01 10:30:00',
        ]]);

        $analyticsQuery = $this->createMock(AnalyticsQueryService::class);
        $analyticsQuery->method('countPageviews')->willReturn(0);
        $analyticsQuery->method('groupBy')->willReturnCallback(
            function (string $siteId, \DateTimeImmutable $s, \DateTimeImmutable $e, string $column) {
                return match ($column) {
                    'country' => [['label' => 'FR', 'count' => 5], ['label' => 'US', 'count' => 2]],
                    'device' => [['label' => 'desktop', 'count' => 6]],
                    default => [],
                };
            }
        );

        $service = new IncidentImpactReportService($incidentQuery, $checkResultRepo, $analyticsQuery);
        $report = $service->generateReport($monitor, $workspace, $start, $end);

        $this->assertSame([['label' => 'FR', 'count' => 5], ['label' => 'US', 'count' => 2]], $report->incidents[0]->topCountriesBaseline);
        $this->assertSame([['label' => 'desktop', 'count' => 6]], $report->incidents[0]->topDevicesBaseline);
    }

    public function testGenerateReportReturnsFullUptimeAndNoIncidentsWhenNoDataInRange(): void
    {
        $monitor = (new Monitor(1, 'Test Site', 'http', 'https://test.com', 5))->setId(10);
        $workspace = new Workspace(1, 'Test Workspace');

        $start = new \DateTimeImmutable('2026-01-01 00:00:00');
        $end = new \DateTimeImmutable('2026-01-03 00:00:00');

        $checkResultRepo = $this->createMock(CheckResultRepositoryInterface::class);
        $checkResultRepo->method('countByStatusInRange')->willReturn(['up' => 0, 'down' => 0, 'timeout' => 0]);

        $incidentQuery = $this->createMock(IncidentQueryService::class);
        $incidentQuery->method('findIncidentsInRange')->willReturn([]);

        $analyticsQuery = $this->createMock(AnalyticsQueryService::class);
        $analyticsQuery->expects($this->never())->method('countPageviews');

        $service = new IncidentImpactReportService($incidentQuery, $checkResultRepo, $analyticsQuery);
        $report = $service->generateReport($monitor, $workspace, $start, $end);

        $this->assertSame(100.0, $report->uptimePercent);
        $this->assertNull($report->mttrMinutes);
        $this->assertSame(0, $report->incidentCount);
        $this->assertSame(0, $report->totalEstimatedLostPageviews);
        $this->assertSame([], $report->incidents);
    }
}
