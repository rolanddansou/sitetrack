<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Monitor;

interface MonitorRepositoryInterface
{
    public function find(int $id): ?Monitor;

    /**
     * @return Monitor[]
     */
    public function findActiveMonitors(): array;

    /**
     * @return Monitor[]
     */
    public function findActiveMonitorsByTenant(int $tenantId): array;

    public function save(Monitor $monitor): void;

    public function delete(Monitor $monitor): void;
}
