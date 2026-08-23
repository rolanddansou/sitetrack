<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Tenant;

interface TenantRepositoryInterface
{
    public function find(int $id): ?Tenant;

    public function save(Tenant $tenant): void;

    public function delete(Tenant $tenant): void;
}
