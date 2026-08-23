<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Entity\Workspace;
use App\Domain\Repository\TenantMembershipRepositoryInterface;
use App\Domain\Repository\WorkspaceRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Resolves a `Workspace $workspace` controller argument from the
 * `{workspacePublicId}` route parameter, and denies access unless the
 * logged-in identity has a TenantMembership on the workspace's owning
 * tenant. Centralizes what would otherwise be repeated load-and-check
 * boilerplate in every workspace-scoped controller action.
 */
class WorkspaceValueResolver implements ValueResolverInterface
{
    public function __construct(
        private WorkspaceRepositoryInterface $workspaceRepository,
        private Security $security,
        private TenantMembershipRepositoryInterface $tenantMembershipRepository
    ) {}

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== Workspace::class) {
            return [];
        }

        $publicId = $request->attributes->get('workspacePublicId');
        if (!is_string($publicId)) {
            return [];
        }

        $workspace = $this->workspaceRepository->findByPublicId($publicId);
        if ($workspace === null) {
            throw new NotFoundHttpException('Workspace not found');
        }

        $user = $this->security->getUser();
        $identityId = $user instanceof IdentityUser ? $user->getIdentity()->getId() : null;
        if ($identityId === null || $this->tenantMembershipRepository->findOneByTenantAndIdentity($workspace->getTenantId(), $identityId) === null) {
            throw new AccessDeniedException('No access to this workspace.');
        }

        return [$workspace];
    }
}
