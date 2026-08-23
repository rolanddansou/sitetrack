<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\UserCredentials;

interface UserCredentialsRepositoryInterface
{
    public function find(int $id): ?UserCredentials;

    public function findByIdentityId(int $identityId): ?UserCredentials;

    public function save(UserCredentials $userCredentials): void;

    public function delete(UserCredentials $userCredentials): void;
}
