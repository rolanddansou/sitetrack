<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Entity\AlertEvent;
use App\Domain\Repository\AlertEventRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineAlertEventRepository implements AlertEventRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(AlertEvent $event): void
    {
        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }

    public function findActiveAlert(int $ruleId): ?AlertEvent
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(AlertEvent::class, 'e')
            ->where('e.ruleId = :ruleId')
            ->andWhere('e.status = :status')
            ->setParameter('ruleId', $ruleId)
            ->setParameter('status', 'triggered')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLastTriggeredAlert(int $ruleId): ?AlertEvent
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(AlertEvent::class, 'e')
            ->where('e.ruleId = :ruleId')
            ->andWhere('e.status = :status')
            ->setParameter('ruleId', $ruleId)
            ->setParameter('status', 'triggered')
            ->orderBy('e.triggeredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
