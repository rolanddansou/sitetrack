<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Entity\SmtpTest;
use App\Domain\Repository\SmtpTestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineSmtpTestRepository implements SmtpTestRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(SmtpTest $smtpTest): void
    {
        $this->entityManager->persist($smtpTest);
        $this->entityManager->flush();
    }

    public function find(string $id): ?SmtpTest
    {
        return $this->entityManager->find(SmtpTest::class, $id);
    }

    public function findExpiredSentTests(\DateTimeImmutable $before): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(SmtpTest::class, 't')
            ->where('t.status = :status')
            ->andWhere('t.sentAt < :before')
            ->setParameter('status', 'sent')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }
}
