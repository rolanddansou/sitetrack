<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Entity\Identity;
use App\Domain\Repository\IdentityRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineIdentityRepository implements IdentityRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?Identity
    {
        return $this->entityManager->find(Identity::class, $id);
    }

    public function findByEmail(string $email): ?Identity
    {
        return $this->entityManager->getRepository(Identity::class)->findOneBy(['email' => $email]);
    }

    public function save(Identity $identity): void
    {
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
    }

    public function delete(Identity $identity): void
    {
        $this->entityManager->remove($identity);
        $this->entityManager->flush();
    }
}
