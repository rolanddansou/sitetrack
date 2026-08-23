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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bulk-creates throwaway users, each with their own tenant, for manual/local
 * testing (e.g. cross-tenant isolation checks) — not for production use.
 * app:create-user remains the single-user bootstrap command.
 */
#[AsCommand(
    name: 'app:create-test-users',
    description: 'Creates N test users, each in their own tenant, for local testing.'
)]
class CreateTestUsersCommand extends Command
{
    private const DEFAULT_PASSWORD = 'password123';

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
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'Number of test users/tenants to create', '3')
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Email/tenant naming prefix', 'test')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password shared by every created user', self::DEFAULT_PASSWORD);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = (int) $input->getOption('count');
        if ($count < 1) {
            $io->error('--count must be at least 1.');
            return Command::FAILURE;
        }

        $prefix = (string) $input->getOption('prefix');
        $password = (string) $input->getOption('password');
        $passwordHash = $this->passwordHasher->hash($password);

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $email = sprintf('%s%d@example.test', $prefix, $i);

            if ($this->identityRepository->findByEmail($email) !== null) {
                $io->writeln(sprintf('<comment>Skipping "%s" — already exists.</comment>', $email));
                continue;
            }

            $tenantName = sprintf('%s Tenant %d', ucfirst($prefix), $i);
            $slug = sprintf('%s-tenant-%d', strtolower($prefix), $i);

            $tenant = new Tenant($tenantName, $slug);
            $this->tenantRepository->save($tenant);

            $identity = new Identity($email);
            $identity->setEnabled(true);
            $this->identityRepository->save($identity);

            $identityId = $identity->getId();
            $tenantId = $tenant->getId();
            if ($identityId === null || $tenantId === null) {
                $io->error(sprintf('Failed to persist identity or tenant for "%s".', $email));
                return Command::FAILURE;
            }

            $credentials = new UserCredentials($identityId, $passwordHash);
            $this->userCredentialsRepository->save($credentials);

            $membership = new TenantMembership($tenantId, $identityId, 'owner');
            $this->tenantMembershipRepository->save($membership);

            $workspace = new Workspace($tenantId, 'Default');
            $this->workspaceRepository->save($workspace);

            $rows[] = [$email, $password, $tenantName, $tenantId];
        }

        if (empty($rows)) {
            $io->warning('No new test users were created.');
            return Command::SUCCESS;
        }

        $io->table(['Email', 'Password', 'Tenant', 'Tenant ID'], $rows);
        $io->success(sprintf('Created %d test user(s), each owner of their own tenant.', count($rows)));

        return Command::SUCCESS;
    }
}
