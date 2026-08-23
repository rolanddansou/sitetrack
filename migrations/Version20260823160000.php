<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the `workspaces` table and backfills exactly one Workspace per
 * existing Tenant, copying that tenant's current site_id (rather than
 * generating a fresh one) so any tracking script already installed on a
 * customer's site keeps working unchanged after this migration.
 *
 * Branches by platform (same pattern as AnalyticsController::dateBucketExpr())
 * because dev runs on MariaDB while tests run on SQLite — SQLite has no
 * portable ALTER TABLE, hence its temp-table rebuild idiom; MySQL/MariaDB
 * get plain CREATE TABLE + a UUID()-generated public_id per row.
 */
final class Version20260823160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create workspaces table, backfill one workspace per existing tenant.';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE workspaces (id INT AUTO_INCREMENT NOT NULL, public_id VARCHAR(36) NOT NULL, tenant_id INT NOT NULL, name VARCHAR(255) NOT NULL, site_id VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_7FE8F3CBB5B48B91 (public_id), UNIQUE INDEX UNIQ_7FE8F3CBF6BD1646 (site_id), INDEX idx_workspaces_tenant (tenant_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql("INSERT INTO workspaces (public_id, tenant_id, name, site_id, created_at, updated_at) SELECT UUID(), id, 'Default', site_id, created_at, updated_at FROM tenants");

            return;
        }

        $this->addSql('CREATE TABLE workspaces (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, public_id VARCHAR(36) NOT NULL, tenant_id INTEGER NOT NULL, name VARCHAR(255) NOT NULL, site_id VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7FE8F3CBB5B48B91 ON workspaces (public_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7FE8F3CBF6BD1646 ON workspaces (site_id)');
        $this->addSql('CREATE INDEX idx_workspaces_tenant ON workspaces (tenant_id)');
        // hex(randomblob(N)) is evaluated per output row here (a real SELECT), giving every
        // backfilled workspace its own unique public_id, same idiom as Version20260823024835.
        // site_id is COPIED from the owning tenant, not regenerated.
        $this->addSql("INSERT INTO workspaces (public_id, tenant_id, name, site_id, created_at, updated_at) SELECT lower(hex(randomblob(4)) || '-' || hex(randomblob(2)) || '-' || hex(randomblob(2)) || '-' || hex(randomblob(2)) || '-' || hex(randomblob(6))), id, 'Default', site_id, created_at, updated_at FROM tenants");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workspaces');
    }
}
