<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\DTO\WorkspaceInputDto;
use App\Domain\Entity\Workspace;
use App\Domain\Repository\WorkspaceRepositoryInterface;
use App\Infrastructure\Security\CurrentTenantResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class WorkspaceController extends AbstractController
{
    public function __construct(
        private WorkspaceRepositoryInterface $workspaceRepository,
        private CurrentTenantResolver $currentTenantResolver,
        private ValidatorInterface $validator
    ) {}

    #[Route('/workspace/new', name: 'workspace_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $dto = new WorkspaceInputDto();
        $errors = [];

        if ($request->isMethod('POST')) {
            $dto->name = (string) $request->request->get('name');

            $validationErrors = $this->validator->validate($dto);
            if (count($validationErrors) > 0) {
                foreach ($validationErrors as $err) {
                    $errors[$err->getPropertyPath()] = $err->getMessage();
                }
            } else {
                $workspace = new Workspace($this->currentTenantResolver->resolve(), $dto->name);
                $this->workspaceRepository->save($workspace);
                $this->addFlash('success', 'Workspace created successfully.');
                return $this->redirectToRoute('workspace_dashboard_index', ['workspacePublicId' => $workspace->getPublicId()]);
            }
        }

        return $this->render('workspace/new.html.twig', [
            'dto' => $dto,
            'errors' => $errors,
        ]);
    }

    #[Route('/workspace/{workspacePublicId}/edit', name: 'workspace_edit', methods: ['GET', 'POST'])]
    public function edit(Workspace $workspace, Request $request): Response
    {
        $dto = new WorkspaceInputDto();
        $dto->name = $workspace->getName();
        $errors = [];

        if ($request->isMethod('POST')) {
            $dto->name = (string) $request->request->get('name');

            $validationErrors = $this->validator->validate($dto);
            if (count($validationErrors) > 0) {
                foreach ($validationErrors as $err) {
                    $errors[$err->getPropertyPath()] = $err->getMessage();
                }
            } else {
                $workspace->setName($dto->name);
                $this->workspaceRepository->save($workspace);
                $this->addFlash('success', 'Workspace renamed successfully.');
                return $this->redirectToRoute('workspace_dashboard_index', ['workspacePublicId' => $workspace->getPublicId()]);
            }
        }

        return $this->render('workspace/edit.html.twig', [
            'workspace' => $workspace,
            'workspaces' => $this->workspaceRepository->findByTenant($workspace->getTenantId()),
            'dto' => $dto,
            'errors' => $errors,
        ]);
    }
}
