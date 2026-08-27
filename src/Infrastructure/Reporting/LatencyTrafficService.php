<?php

declare(strict_types=1);

namespace App\Infrastructure\Reporting;

use App\Domain\DTO\LatencyTrafficPointDto;
use App\Domain\DTO\LatencyTrafficSeriesDto;
use App\Domain\Entity\Monitor;
use App\Domain\Entity\Workspace;
use App\Infrastructure\Analytics\AnalyticsQueryService;
use Doctrine\DBAL\Connection;

/**
 * Correlates a monitor's response latency with its workspace's traffic
 * volume, day by day — independent of any incident (unlike
 * IncidentImpactReportService): a monitor can be "up" the whole time and
 * still be trending slower under heavier load, which is worth surfacing
 * before it becomes an actual outage.
 */
class LatencyTrafficService
{
    public function __construct(
        private Connection $connection,
        private AnalyticsQueryService $analyticsQuery
    ) {}

    public function buildSeries(Monitor $monitor, Workspace $workspace, \DateTimeImmutable $start, \DateTimeImmutable $end): LatencyTrafficSeriesDto
    {
        $latencyByDay = $this->avgLatencyByDay($monitor->getId(), $start, $end);
        $pageviewsByDay = $this->analyticsQuery->pageviewsByDay($workspace->getSiteId(), $start, $end);

        $points = [];
        $latencyValues = [];
        $pageviewValues = [];

        $cursor = $start->setTime(0, 0);
        $lastDay = $end->setTime(0, 0);
        while ($cursor <= $lastDay) {
            $key = $cursor->format('Y-m-d');
            $avgLatency = $latencyByDay[$key] ?? null;
            $pageviews = $pageviewsByDay[$key] ?? 0;

            $points[] = new LatencyTrafficPointDto($key, $avgLatency, $pageviews);

            if ($avgLatency !== null && $pageviews > 0) {
                $latencyValues[] = $avgLatency;
                $pageviewValues[] = $pageviews;
            }

            $cursor = $cursor->modify('+1 day');
        }

        $correlation = count($latencyValues) >= 2 ? $this->pearsonCorrelation($latencyValues, $pageviewValues) : null;

        return new LatencyTrafficSeriesDto($points, $correlation);
    }

    /**
     * Only successful ('up') checks — a timeout's "response time" is an
     * artifact of the timeout duration, not a real latency measurement, and
     * would otherwise dominate the average.
     *
     * @return array<string, float>
     */
    private function avgLatencyByDay(int $monitorId, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $bucketExpr = $this->analyticsQuery->dateBucketExpr('checked_at', 'daily');

        $rows = $this->connection->createQueryBuilder()
            ->select(sprintf('%s as bucket, AVG(response_time_ms) as avg_latency', $bucketExpr))
            ->from('checks_results')
            ->where('monitor_id = :monitorId')
            ->andWhere('status = :status')
            ->andWhere('checked_at BETWEEN :start AND :end')
            ->groupBy('bucket')
            ->setParameter('monitorId', $monitorId)
            ->setParameter('status', 'up')
            ->setParameter('start', $start->format('Y-m-d H:i:s'))
            ->setParameter('end', $end->format('Y-m-d H:i:s'))
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['bucket']] = (float) $row['avg_latency'];
        }

        return $result;
    }

    /**
     * @param float[] $x
     * @param int[] $y
     */
    private function pearsonCorrelation(array $x, array $y): float
    {
        $n = count($x);
        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $numerator = 0.0;
        $sumSqX = 0.0;
        $sumSqY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $numerator += $dx * $dy;
            $sumSqX += $dx ** 2;
            $sumSqY += $dy ** 2;
        }

        $denominator = sqrt($sumSqX * $sumSqY);

        return $denominator > 0.0 ? round($numerator / $denominator, 2) : 0.0;
    }
}
