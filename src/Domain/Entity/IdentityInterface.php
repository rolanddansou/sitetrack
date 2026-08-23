<?php

declare(strict_types=1);

namespace App\Domain\Entity;

interface IdentityInterface
{
    public function getUserId(): int;

    public function getUserEmail(): string;
}
