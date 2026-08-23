<?php

declare(strict_types=1);

namespace App\Domain\Service;

interface PasswordHasherInterface
{
    public function hash(string $plainPassword): string;

    public function verify(string $hashedPassword, string $plainPassword): bool;
}
