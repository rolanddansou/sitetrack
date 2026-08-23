<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Workspace;

interface WorkspaceRepositoryInterface
{
    public function find(int $id): ?Workspace;

    public function findByPublicId(string $publicId): ?Workspace;

    /**
     * @return Workspace[]
     */
    public function findByTenant(int $tenantId): array;

    public function save(Workspace $workspace): void;

    public function delete(Workspace $workspace): void;
}
