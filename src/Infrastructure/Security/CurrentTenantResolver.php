<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Repository\TenantMembershipRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class CurrentTenantResolver
{
    public function __construct(
        private Security $security,
        private TenantMembershipRepositoryInterface $tenantMembershipRepository
    ) {}

    public function resolve(): int
    {
        /** @var IdentityUser $user */
        $user = $this->security->getUser();
        $identityId = $user->getIdentity()->getId();

        $memberships = $this->tenantMembershipRepository->findByIdentityId($identityId ?? 0);
        if (count($memberships) === 0) {
            throw new AccessDeniedException('No tenant membership found for this identity.');
        }

        return $memberships[0]->getTenantId();
    }
}
