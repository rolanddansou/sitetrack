<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Entity\CheckResult;
use App\Domain\Repository\CheckResultRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineCheckResultRepository implements CheckResultRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(CheckResult $checkResult): void
    {
        $this->entityManager->persist($checkResult);
        $this->entityManager->flush();
    }

    public function findRecentResults(int $monitorId, int $limit = 10): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(CheckResult::class, 'r')
            ->where('r.monitorId = :monitorId')
            ->setParameter('monitorId', $monitorId)
            ->orderBy('r.checkedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findConsecutiveFailures(int $monitorId): int
    {
        $results = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(CheckResult::class, 'r')
            ->where('r.monitorId = :monitorId')
            ->setParameter('monitorId', $monitorId)
            ->orderBy('r.checkedAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($results as $res) {
            if ($res->getStatus() !== 'up') {
                $count++;
            } else {
                break;
            }
        }
        return $count;
    }
}
