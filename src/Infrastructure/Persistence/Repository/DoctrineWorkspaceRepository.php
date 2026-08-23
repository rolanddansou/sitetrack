<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Entity\Workspace;
use App\Domain\Repository\WorkspaceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineWorkspaceRepository implements WorkspaceRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?Workspace
    {
        return $this->entityManager->find(Workspace::class, $id);
    }

    public function findByPublicId(string $publicId): ?Workspace
    {
        return $this->entityManager->getRepository(Workspace::class)->findOneBy(['publicId' => $publicId]);
    }

    public function findByTenant(int $tenantId): array
    {
        return $this->entityManager->getRepository(Workspace::class)->findBy(['tenantId' => $tenantId], ['createdAt' => 'ASC']);
    }

    public function save(Workspace $workspace): void
    {
        $this->entityManager->persist($workspace);
        $this->entityManager->flush();
    }

    public function delete(Workspace $workspace): void
    {
        $this->entityManager->remove($workspace);
        $this->entityManager->flush();
    }
}
