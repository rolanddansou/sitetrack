<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\AlertRule;

interface AlertRuleRepositoryInterface
{
    /**
     * @return AlertRule[]
     */
    public function findByMonitor(int $monitorId): array;

    public function save(AlertRule $rule): void;

    public function delete(AlertRule $rule): void;
}
