<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Entity\Monitor;
use App\Domain\Repository\TenantMembershipRepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class MonitorVoter extends Voter
{
    public const MONITOR_ACCESS = 'MONITOR_ACCESS';

    public function __construct(private TenantMembershipRepositoryInterface $tenantMembershipRepository) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MONITOR_ACCESS && $subject instanceof Monitor;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof IdentityUser) {
            return false;
        }

        /** @var Monitor $monitor */
        $monitor = $subject;
        $identityId = $user->getIdentity()->getId();
        if ($identityId === null) {
            return false;
        }

        return $this->tenantMembershipRepository->findOneByTenantAndIdentity($monitor->getTenantId(), $identityId) !== null;
    }
}
