<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Service\PasswordHasherInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

class SymfonyPasswordHasher implements PasswordHasherInterface
{
    public function __construct(private PasswordHasherFactoryInterface $hasherFactory) {}

    public function hash(string $plainPassword): string
    {
        return $this->hasherFactory->getPasswordHasher(IdentityUser::class)->hash($plainPassword);
    }

    public function verify(string $hashedPassword, string $plainPassword): bool
    {
        return $this->hasherFactory->getPasswordHasher(IdentityUser::class)->verify($hashedPassword, $plainPassword);
    }
}
