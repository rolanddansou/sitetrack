<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Entity\AlertRule;
use App\Domain\Repository\AlertRuleRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineAlertRuleRepository implements AlertRuleRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function findByMonitor(int $monitorId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AlertRule::class, 'r')
            ->where('r.monitorId = :monitorId')
            ->setParameter('monitorId', $monitorId)
            ->getQuery()
            ->getResult();
    }

    public function save(AlertRule $rule): void
    {
        $this->entityManager->persist($rule);
        $this->entityManager->flush();
    }

    public function delete(AlertRule $rule): void
    {
        $this->entityManager->remove($rule);
        $this->entityManager->flush();
    }
}
