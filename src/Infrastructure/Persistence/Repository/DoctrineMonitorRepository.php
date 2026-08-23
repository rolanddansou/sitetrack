<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Entity\Monitor;
use App\Domain\Repository\MonitorRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineMonitorRepository implements MonitorRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?Monitor
    {
        return $this->entityManager->find(Monitor::class, $id);
    }

    public function findByPublicId(string $publicId): ?Monitor
    {
        return $this->entityManager->getRepository(Monitor::class)->findOneBy(['publicId' => $publicId]);
    }

    public function findActiveMonitors(): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(Monitor::class, 'm')
            ->getQuery()
            ->getResult();
    }

    public function findActiveMonitorsByTenant(int $tenantId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(Monitor::class, 'm')
            ->where('m.tenantId = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getResult();
    }

    public function save(Monitor $monitor): void
    {
        $this->entityManager->persist($monitor);
        $this->entityManager->flush();
    }

    public function delete(Monitor $monitor): void
    {
        $this->entityManager->remove($monitor);
        $this->entityManager->flush();
    }
}
