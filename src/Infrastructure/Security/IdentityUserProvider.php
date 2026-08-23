<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Repository\IdentityRepositoryInterface;
use App\Domain\Repository\UserCredentialsRepositoryInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class IdentityUserProvider implements UserProviderInterface
{
    public function __construct(
        private IdentityRepositoryInterface $identityRepository,
        private UserCredentialsRepositoryInterface $userCredentialsRepository
    ) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $identity = $this->identityRepository->findByEmail($identifier);
        if ($identity === null || $identity->getId() === null) {
            throw new UserNotFoundException(sprintf('Identity with email "%s" not found.', $identifier));
        }

        if (!$identity->isEnabled()) {
            throw new DisabledException(sprintf('Identity with email "%s" is disabled.', $identifier));
        }

        $credentials = $this->userCredentialsRepository->findByIdentityId($identity->getId());
        if ($credentials === null) {
            throw new UserNotFoundException(sprintf('No credentials found for identity "%s".', $identifier));
        }

        return new IdentityUser($identity, $credentials);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof IdentityUser) {
            throw new UnsupportedUserException(sprintf('Invalid user class "%s".', get_class($user)));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === IdentityUser::class;
    }
}
