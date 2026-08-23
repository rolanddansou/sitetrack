<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Entity\TenantMembership;
use App\Domain\Repository\TenantMembershipRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineTenantMembershipRepository implements TenantMembershipRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?TenantMembership
    {
        return $this->entityManager->find(TenantMembership::class, $id);
    }

    public function findByIdentityId(int $identityId): array
    {
        return $this->entityManager->getRepository(TenantMembership::class)->findBy(['identityId' => $identityId]);
    }

    public function findOneByTenantAndIdentity(int $tenantId, int $identityId): ?TenantMembership
    {
        return $this->entityManager->getRepository(TenantMembership::class)->findOneBy([
            'tenantId' => $tenantId,
            'identityId' => $identityId,
        ]);
    }

    public function save(TenantMembership $tenantMembership): void
    {
        $this->entityManager->persist($tenantMembership);
        $this->entityManager->flush();
    }

    public function delete(TenantMembership $tenantMembership): void
    {
        $this->entityManager->remove($tenantMembership);
        $this->entityManager->flush();
    }
}
