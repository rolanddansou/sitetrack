<?php

declare(strict_types=1);

namespace App\Presentation\Command;

use App\Domain\Entity\Identity;
use App\Domain\Entity\Tenant;
use App\Domain\Entity\TenantMembership;
use App\Domain\Entity\UserCredentials;
use App\Domain\Entity\Workspace;
use App\Domain\Repository\IdentityRepositoryInterface;
use App\Domain\Repository\TenantMembershipRepositoryInterface;
use App\Domain\Repository\TenantRepositoryInterface;
use App\Domain\Repository\UserCredentialsRepositoryInterface;
use App\Domain\Repository\WorkspaceRepositoryInterface;
use App\Domain\Service\PasswordHasherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:create-user',
    description: 'Creates a Tenant + Identity + UserCredentials + owner TenantMembership in one shot.'
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private IdentityRepositoryInterface $identityRepository,
        private UserCredentialsRepositoryInterface $userCredentialsRepository,
        private TenantRepositoryInterface $tenantRepository,
        private TenantMembershipRepositoryInterface $tenantMembershipRepository,
        private WorkspaceRepositoryInterface $workspaceRepository,
        private PasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Login email for the new user')
            ->addArgument('password', InputArgument::OPTIONAL, 'Password (omit to be prompted, hidden)')
            ->addOption('tenant-name', null, InputOption::VALUE_REQUIRED, 'Name of the tenant/workspace to create');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        if ($this->identityRepository->findByEmail($email) !== null) {
            $io->error(sprintf('An identity with email "%s" already exists.', $email));
            return Command::FAILURE;
        }

        $password = $input->getArgument('password');
        if ($password === null) {
            $question = new Question('Password: ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = $io->askQuestion($question);
        }
        $password = (string) $password;
        if ($password === '') {
            $io->error('Password cannot be empty.');
            return Command::FAILURE;
        }

        $tenantName = (string) ($input->getOption('tenant-name') ?? explode('@', $email)[0]);
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $tenantName) ?? $tenantName);
        $slug = trim($slug, '-') ?: 'tenant';

        $tenant = new Tenant($tenantName, $slug);
        $this->tenantRepository->save($tenant);

        $identity = new Identity($email);
        $identity->setEnabled(true);
        $this->identityRepository->save($identity);

        $identityId = $identity->getId();
        $tenantId = $tenant->getId();
        if ($identityId === null || $tenantId === null) {
            $io->error('Failed to persist identity or tenant.');
            return Command::FAILURE;
        }

        $credentials = new UserCredentials($identityId, $this->passwordHasher->hash($password));
        $this->userCredentialsRepository->save($credentials);

        $membership = new TenantMembership($tenantId, $identityId, 'owner');
        $this->tenantMembershipRepository->save($membership);

        $workspace = new Workspace($tenantId, 'Default');
        $this->workspaceRepository->save($workspace);

        $io->success(sprintf('Created identity "%s" as owner of tenant "%s" (id %d) with a default workspace.', $email, $tenantName, $tenantId));

        return Command::SUCCESS;
    }
}
