<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Identity;

interface IdentityRepositoryInterface
{
    public function find(int $id): ?Identity;

    public function findByEmail(string $email): ?Identity;

    public function save(Identity $identity): void;

    public function delete(Identity $identity): void;
}
