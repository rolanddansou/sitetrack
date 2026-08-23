<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Domain\Entity\Identity;
use App\Domain\Entity\TenantMembership;
use App\Domain\Entity\UserCredentials;
use App\Domain\Repository\TenantMembershipRepositoryInterface;
use App\Infrastructure\Security\CurrentTenantResolver;
use App\Infrastructure\Security\IdentityUser;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class CurrentTenantResolverTest extends TestCase
{
    public function testResolveReturnsTenantIdOfFirstMembership(): void
    {
        $identity = (new Identity('user@example.test'))->setId(7);
        $user = new IdentityUser($identity, new UserCredentials(7, 'hashed-password'));

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $membershipRepo = $this->createMock(TenantMembershipRepositoryInterface::class);
        $membershipRepo->method('findByIdentityId')->with(7)->willReturn([
            new TenantMembership(42, 7, 'owner'),
        ]);

        $resolver = new CurrentTenantResolver($security, $membershipRepo);

        $this->assertSame(42, $resolver->resolve());
    }

    public function testResolveThrowsWhenIdentityHasNoMembership(): void
    {
        $identity = (new Identity('user@example.test'))->setId(7);
        $user = new IdentityUser($identity, new UserCredentials(7, 'hashed-password'));

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $membershipRepo = $this->createMock(TenantMembershipRepositoryInterface::class);
        $membershipRepo->method('findByIdentityId')->with(7)->willReturn([]);

        $resolver = new CurrentTenantResolver($security, $membershipRepo);

        $this->expectException(AccessDeniedException::class);
        $resolver->resolve();
    }
}
