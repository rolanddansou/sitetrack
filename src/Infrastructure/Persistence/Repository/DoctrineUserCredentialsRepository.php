<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Entity\UserCredentials;
use App\Domain\Repository\UserCredentialsRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineUserCredentialsRepository implements UserCredentialsRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?UserCredentials
    {
        return $this->entityManager->find(UserCredentials::class, $id);
    }

    public function findByIdentityId(int $identityId): ?UserCredentials
    {
        return $this->entityManager->getRepository(UserCredentials::class)->findOneBy(['identityId' => $identityId]);
    }

    public function save(UserCredentials $userCredentials): void
    {
        $this->entityManager->persist($userCredentials);
        $this->entityManager->flush();
    }

    public function delete(UserCredentials $userCredentials): void
    {
        $this->entityManager->remove($userCredentials);
        $this->entityManager->flush();
    }
}
