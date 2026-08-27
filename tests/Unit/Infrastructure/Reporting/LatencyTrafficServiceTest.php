<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Reporting;

use App\Infrastructure\Analytics\AnalyticsQueryService;
use App\Infrastructure\Reporting\LatencyTrafficService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class LatencyTrafficServiceTest extends TestCase
{
    /**
     * pearsonCorrelation() is pure math with no I/O — exercised directly via
     * reflection rather than through buildSeries(), which would otherwise
     * require mocking a long DBAL QueryBuilder fluent chain for no real gain
     * in confidence (the DB-integration half is covered by
     * ReportControllerTest::testReportShowsLatencyTrafficCorrelation()).
     */
    private function correlation(array $x, array $y): float
    {
        $service = new LatencyTrafficService(
            $this->createMock(Connection::class),
            $this->createMock(AnalyticsQueryService::class)
        );

        $method = new \ReflectionMethod($service, 'pearsonCorrelation');
        $method->setAccessible(true);

        return $method->invoke($service, $x, $y);
    }

    public function testPerfectPositiveCorrelation(): void
    {
        $this->assertSame(1.0, $this->correlation([100.0, 200.0, 300.0], [10, 20, 30]));
    }

    public function testPerfectNegativeCorrelation(): void
    {
        $this->assertSame(-1.0, $this->correlation([300.0, 200.0, 100.0], [10, 20, 30]));
    }

    public function testNoVarianceInLatencyReturnsZero(): void
    {
        // Every latency value is identical — no correlation is computable
        // (division by zero denominator), must degrade to 0.0, not NAN/error.
        $this->assertSame(0.0, $this->correlation([150.0, 150.0, 150.0], [10, 20, 30]));
    }

    public function testWeakOrNoCorrelation(): void
    {
        $result = $this->correlation([100.0, 400.0, 150.0, 380.0], [10, 12, 40, 8]);
        $this->assertGreaterThanOrEqual(-1.0, $result);
        $this->assertLessThanOrEqual(1.0, $result);
    }
}
