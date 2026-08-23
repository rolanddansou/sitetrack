<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\CheckResult;

interface CheckResultRepositoryInterface
{
    public function save(CheckResult $checkResult): void;

    /**
     * @return CheckResult[]
     */
    public function findRecentResults(int $monitorId, int $limit = 10): array;

    public function findConsecutiveFailures(int $monitorId): int;
}
