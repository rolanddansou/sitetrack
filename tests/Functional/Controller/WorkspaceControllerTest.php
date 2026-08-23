<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

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
use App\Infrastructure\Security\IdentityUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WorkspaceControllerTest extends WebTestCase
{
    private ?Connection $connection = null;
    private int $tenantId = 0;
    private string $workspacePublicId = '';

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement('DELETE FROM workspaces');
            $this->connection->executeStatement('DELETE FROM tenant_memberships');
            $this->connection->executeStatement('DELETE FROM tenants');
            $this->connection->executeStatement('DELETE FROM user_credentials');
            $this->connection->executeStatement('DELETE FROM identities');
        }
        $this->connection = null;
        parent::tearDown();
    }

    private function createLoggedInUser(KernelBrowser $client): IdentityUser
    {
        $container = static::getContainer();
        $this->connection = $container->get(Connection::class);

        $tenantRepo = $container->get(TenantRepositoryInterface::class);
        $identityRepo = $container->get(IdentityRepositoryInterface::class);
        $credentialsRepo = $container->get(UserCredentialsRepositoryInterface::class);
        $membershipRepo = $container->get(TenantMembershipRepositoryInterface::class);
        $workspaceRepo = $container->get(WorkspaceRepositoryInterface::class);
        $hasher = $container->get(PasswordHasherInterface::class);

        $tenant = new Tenant('Test Tenant', 'test-tenant');
        $tenantRepo->save($tenant);
        $this->tenantId = $tenant->getId();

        $identity = new Identity('user@example.test');
        $identityRepo->save($identity);

        $credentials = new UserCredentials($identity->getId(), $hasher->hash('password'));
        $credentialsRepo->save($credentials);

        $membershipRepo->save(new TenantMembership($tenant->getId(), $identity->getId(), 'owner'));

        $workspace = new Workspace($tenant->getId(), 'Default');
        $workspaceRepo->save($workspace);
        $this->workspacePublicId = $workspace->getPublicId();

        return new IdentityUser($identity, $credentials);
    }

    public function testCreatingAWorkspaceRedirectsToItsDashboard(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $client->request('POST', '/workspace/new', ['name' => 'Marketing Site']);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Marketing Site');

        $workspaceRepo = static::getContainer()->get(WorkspaceRepositoryInterface::class);
        $workspaces = $workspaceRepo->findByTenant($this->tenantId);
        $this->assertCount(2, $workspaces);
    }

    public function testCreatingAWorkspaceWithBlankNameReRendersFormWithError(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $client->request('POST', '/workspace/new', ['name' => '']);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.text-alert');

        $workspaceRepo = static::getContainer()->get(WorkspaceRepositoryInterface::class);
        $this->assertCount(1, $workspaceRepo->findByTenant($this->tenantId));
    }

    public function testRenamingAWorkspaceUpdatesItsName(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $client->request('POST', '/workspace/' . $this->workspacePublicId . '/edit', ['name' => 'Renamed Workspace']);

        $this->assertResponseRedirects('/workspace/' . $this->workspacePublicId . '/dashboard');

        $workspaceRepo = static::getContainer()->get(WorkspaceRepositoryInterface::class);
        $this->assertSame('Renamed Workspace', $workspaceRepo->findByPublicId($this->workspacePublicId)->getName());
    }

    public function testEditingAnotherTenantsWorkspaceIsDenied(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createLoggedInUser($client));

        $container = static::getContainer();
        $otherTenant = new Tenant('Other Tenant', 'other-tenant');
        $container->get(TenantRepositoryInterface::class)->save($otherTenant);
        $otherWorkspace = new Workspace($otherTenant->getId(), 'Default');
        $container->get(WorkspaceRepositoryInterface::class)->save($otherWorkspace);

        $client->request('GET', '/workspace/' . $otherWorkspace->getPublicId() . '/edit');

        $this->assertResponseStatusCodeSame(403);
    }
}
