<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\DTO\LatencyTrafficSeriesDto;
use App\Domain\Entity\Monitor;
use App\Domain\Entity\Workspace;
use App\Domain\Repository\MonitorRepositoryInterface;
use App\Domain\Repository\WorkspaceRepositoryInterface;
use App\Domain\Service\PdfRendererInterface;
use App\Infrastructure\Reporting\IncidentImpactReportService;
use App\Infrastructure\Reporting\LatencyTrafficService;
use App\Infrastructure\Reporting\WorkspaceReportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReportController extends AbstractController
{
    private const PERIODS = ['7d', '30d', '90d'];

    public function __construct(
        private MonitorRepositoryInterface $monitorRepository,
        private WorkspaceRepositoryInterface $workspaceRepository,
        private IncidentImpactReportService $reportService,
        private WorkspaceReportService $workspaceReportService,
        private LatencyTrafficService $latencyTrafficService
    ) {}

    #[Route('/workspace/{workspacePublicId}/report', name: 'workspace_report', methods: ['GET'])]
    public function workspaceShow(Workspace $workspace, Request $request): Response
    {
        $period = $this->resolvePeriod((string) $request->query->get('period', ''));
        [$start, $end] = $this->resolveRange($period);

        $report = $this->workspaceReportService->generateReport($workspace, $start, $end);

        return $this->render('report/workspace.html.twig', [
            'workspace' => $workspace,
            'workspaces' => $this->workspaceRepository->findByTenant($workspace->getTenantId()),
            'period' => $period,
            'report' => $report,
        ]);
    }

    #[Route('/workspace/{workspacePublicId}/monitor/{monitorPublicId}/report', name: 'workspace_monitor_report', methods: ['GET'])]
    public function show(Workspace $workspace, string $monitorPublicId, Request $request): Response
    {
        $monitor = $this->findMonitorInWorkspace($workspace, $monitorPublicId);
        $period = $this->resolvePeriod((string) $request->query->get('period', ''));
        [$start, $end] = $this->resolveRange($period);

        $report = $this->reportService->generateReport($monitor, $workspace, $start, $end);
        $latencyTraffic = $this->latencyTrafficService->buildSeries($monitor, $workspace, $start, $end);

        return $this->render('report/show.html.twig', [
            'workspace' => $workspace,
            'workspaces' => $this->workspaceRepository->findByTenant($workspace->getTenantId()),
            'monitor' => $monitor,
            'period' => $period,
            'report' => $report,
            'latencyTraffic' => $latencyTraffic,
            'latencyTrafficChartData' => $this->buildLatencyTrafficChartData($latencyTraffic),
        ]);
    }

    /**
     * @return array{labels: array<int, string>, datasets: array<int, array<string, mixed>>}
     */
    private function buildLatencyTrafficChartData(LatencyTrafficSeriesDto $series): array
    {
        $labels = [];
        $latency = [];
        $pageviews = [];
        foreach ($series->points as $point) {
            $labels[] = $point->date;
            $latency[] = $point->avgLatencyMs;
            $pageviews[] = $point->pageviews;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Avg Latency (ms)',
                    'data' => $latency,
                    'yAxisID' => 'y',
                    'borderColor' => '#C15A2B',
                    'backgroundColor' => 'rgba(193, 90, 43, 0.1)',
                    'tension' => 0.3,
                    'spanGaps' => true,
                ],
                [
                    'label' => 'Pageviews',
                    'data' => $pageviews,
                    'yAxisID' => 'y1',
                    'borderColor' => '#0E7C6B',
                    'backgroundColor' => 'rgba(14, 124, 107, 0.1)',
                    'tension' => 0.3,
                ],
            ],
        ];
    }

    #[Route('/workspace/{workspacePublicId}/monitor/{monitorPublicId}/report/pdf', name: 'workspace_monitor_report_pdf', methods: ['GET'])]
    public function pdf(Workspace $workspace, string $monitorPublicId, Request $request, PdfRendererInterface $pdfRenderer): Response
    {
        $monitor = $this->findMonitorInWorkspace($workspace, $monitorPublicId);
        $period = $this->resolvePeriod((string) $request->query->get('period', ''));
        [$start, $end] = $this->resolveRange($period);

        $report = $this->reportService->generateReport($monitor, $workspace, $start, $end);

        $html = $this->renderView('report/pdf.html.twig', [
            'monitor' => $monitor,
            'period' => $period,
            'report' => $report,
        ]);
        $pdfContent = $pdfRenderer->render($html);

        $filename = sprintf('incident-report-%s-%s.pdf', $monitor->getPublicId(), $period);

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    private function resolvePeriod(string $period): string
    {
        return in_array($period, self::PERIODS, true) ? $period : '7d';
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolveRange(string $period): array
    {
        $now = new \DateTimeImmutable();
        $start = match ($period) {
            '30d' => $now->modify('-30 days'),
            '90d' => $now->modify('-90 days'),
            default => $now->modify('-7 days'),
        };

        return [$start, $now];
    }

    /**
     * Same "mismatched monitor 404s, doesn't 403" convention as
     * DashboardController::findMonitorInWorkspace() — WorkspaceValueResolver
     * already proved access to $workspace itself.
     */
    private function findMonitorInWorkspace(Workspace $workspace, string $monitorPublicId): Monitor
    {
        $monitor = $this->monitorRepository->findByPublicId($monitorPublicId);
        if ($monitor === null || $monitor->getWorkspaceId() !== $workspace->getId()) {
            throw $this->createNotFoundException('Monitor not found');
        }

        return $monitor;
    }
}
