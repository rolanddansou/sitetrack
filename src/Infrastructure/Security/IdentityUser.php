<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Entity\Identity;
use App\Domain\Entity\UserCredentials;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class IdentityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        private Identity $identity,
        private UserCredentials $credentials
    ) {}

    public function getIdentity(): Identity
    {
        return $this->identity;
    }

    public function getUserIdentifier(): string
    {
        return $this->identity->getEmail();
    }

    public function getPassword(): ?string
    {
        return $this->credentials->getPasswordHash();
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
