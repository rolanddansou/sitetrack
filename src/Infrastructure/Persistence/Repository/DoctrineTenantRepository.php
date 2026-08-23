<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Entity\Tenant;
use App\Domain\Repository\TenantRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineTenantRepository implements TenantRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?Tenant
    {
        return $this->entityManager->find(Tenant::class, $id);
    }

    public function save(Tenant $tenant): void
    {
        $this->entityManager->persist($tenant);
        $this->entityManager->flush();
    }

    public function delete(Tenant $tenant): void
    {
        $this->entityManager->remove($tenant);
        $this->entityManager->flush();
    }
}
