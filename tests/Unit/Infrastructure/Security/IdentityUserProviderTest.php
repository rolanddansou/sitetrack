<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Domain\Entity\Identity;
use App\Domain\Entity\UserCredentials;
use App\Domain\Repository\IdentityRepositoryInterface;
use App\Domain\Repository\UserCredentialsRepositoryInterface;
use App\Infrastructure\Security\IdentityUser;
use App\Infrastructure\Security\IdentityUserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

class IdentityUserProviderTest extends TestCase
{
    public function testLoadUserByIdentifierReturnsIdentityUser(): void
    {
        $identity = (new Identity('user@example.test'))->setId(1);
        $credentials = new UserCredentials(1, 'hashed-password');

        $identityRepo = $this->createMock(IdentityRepositoryInterface::class);
        $identityRepo->method('findByEmail')->with('user@example.test')->willReturn($identity);

        $credentialsRepo = $this->createMock(UserCredentialsRepositoryInterface::class);
        $credentialsRepo->method('findByIdentityId')->with(1)->willReturn($credentials);

        $provider = new IdentityUserProvider($identityRepo, $credentialsRepo);
        $user = $provider->loadUserByIdentifier('user@example.test');

        $this->assertInstanceOf(IdentityUser::class, $user);
        $this->assertSame('user@example.test', $user->getUserIdentifier());
        $this->assertSame('hashed-password', $user->getPassword());
    }

    public function testLoadUserByIdentifierThrowsWhenIdentityNotFound(): void
    {
        $identityRepo = $this->createMock(IdentityRepositoryInterface::class);
        $identityRepo->method('findByEmail')->willReturn(null);

        $credentialsRepo = $this->createMock(UserCredentialsRepositoryInterface::class);

        $provider = new IdentityUserProvider($identityRepo, $credentialsRepo);

        $this->expectException(UserNotFoundException::class);
        $provider->loadUserByIdentifier('missing@example.test');
    }

    public function testLoadUserByIdentifierThrowsWhenCredentialsMissing(): void
    {
        $identity = (new Identity('user@example.test'))->setId(1);

        $identityRepo = $this->createMock(IdentityRepositoryInterface::class);
        $identityRepo->method('findByEmail')->willReturn($identity);

        $credentialsRepo = $this->createMock(UserCredentialsRepositoryInterface::class);
        $credentialsRepo->method('findByIdentityId')->with(1)->willReturn(null);

        $provider = new IdentityUserProvider($identityRepo, $credentialsRepo);

        $this->expectException(UserNotFoundException::class);
        $provider->loadUserByIdentifier('user@example.test');
    }

    public function testLoadUserByIdentifierThrowsDisabledExceptionWhenIdentityIsDisabled(): void
    {
        $identity = (new Identity('user@example.test'))->setId(1)->setEnabled(false);

        $identityRepo = $this->createMock(IdentityRepositoryInterface::class);
        $identityRepo->method('findByEmail')->willReturn($identity);

        $credentialsRepo = $this->createMock(UserCredentialsRepositoryInterface::class);
        $credentialsRepo->expects($this->never())->method('findByIdentityId');

        $provider = new IdentityUserProvider($identityRepo, $credentialsRepo);

        $this->expectException(DisabledException::class);
        $provider->loadUserByIdentifier('user@example.test');
    }

    public function testSupportsClass(): void
    {
        $provider = new IdentityUserProvider(
            $this->createMock(IdentityRepositoryInterface::class),
            $this->createMock(UserCredentialsRepositoryInterface::class)
        );

        $this->assertTrue($provider->supportsClass(IdentityUser::class));
        $this->assertFalse($provider->supportsClass(Identity::class));
    }
}
