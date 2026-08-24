<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Monitoring;

use App\Infrastructure\Monitoring\QueryCounter;
use PHPUnit\Framework\TestCase;

class QueryCounterTest extends TestCase
{
    public function testCountsOneLogCallPerQueryAndResetsBackToZero(): void
    {
        $counter = new QueryCounter();

        $this->assertSame(0, $counter->count());

        $counter->debug('SELECT 1');
        $counter->debug('SELECT 2');
        $counter->debug('SELECT 3');
        $this->assertSame(3, $counter->count());

        $counter->reset();
        $this->assertSame(0, $counter->count());
    }
}
