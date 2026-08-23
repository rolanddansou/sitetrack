<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Monitor;

interface MonitorRepositoryInterface
{
    public function find(int $id): ?Monitor;

    public function findByPublicId(string $publicId): ?Monitor;

    /**
     * @return Monitor[]
     */
    public function findActiveMonitors(): array;

    /**
     * @return Monitor[]
     */
    public function findActiveMonitorsByWorkspace(int $workspaceId): array;

    public function save(Monitor $monitor): void;

    public function delete(Monitor $monitor): void;
}
