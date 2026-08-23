<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\TenantMembership;

interface TenantMembershipRepositoryInterface
{
    public function find(int $id): ?TenantMembership;

    /**
     * @return TenantMembership[]
     */
    public function findByIdentityId(int $identityId): array;

    public function findOneByTenantAndIdentity(int $tenantId, int $identityId): ?TenantMembership;

    public function save(TenantMembership $tenantMembership): void;

    public function delete(TenantMembership $tenantMembership): void;
}
