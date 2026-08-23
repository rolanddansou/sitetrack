<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops tenants.site_id now that every tenant's site_id has been copied onto
 * its default workspace (Version20260823160000). Removing it avoids a stale
 * second source of truth for the analytics tracking identifier.
 */
final class Version20260823160002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop tenants.site_id — ownership moved to workspaces.site_id.';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE tenants DROP INDEX UNIQ_B8FC96BBF6BD1646');
            $this->addSql('ALTER TABLE tenants DROP COLUMN site_id');

            return;
        }

        $this->addSql('CREATE TEMPORARY TABLE __temp__tenants AS SELECT name, slug, is_active, created_at, updated_at, id FROM tenants');
        $this->addSql('DROP TABLE tenants');
        $this->addSql('CREATE TABLE tenants (name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO tenants (name, slug, is_active, created_at, updated_at, id) SELECT name, slug, is_active, created_at, updated_at, id FROM __temp__tenants');
        $this->addSql('DROP TABLE __temp__tenants');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B8FC96BB989D9B62 ON tenants (slug)');
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE tenants ADD site_id VARCHAR(32) DEFAULT NULL');
            // Restore from the tenant's default workspace (lowest workspace id = first created for that tenant).
            $this->addSql('UPDATE tenants t INNER JOIN (SELECT tenant_id, MIN(id) AS min_id FROM workspaces GROUP BY tenant_id) first_ws ON first_ws.tenant_id = t.id INNER JOIN workspaces w ON w.id = first_ws.min_id SET t.site_id = w.site_id');
            $this->addSql('ALTER TABLE tenants MODIFY site_id VARCHAR(32) NOT NULL');
            $this->addSql('ALTER TABLE tenants ADD UNIQUE INDEX UNIQ_B8FC96BBF6BD1646 (site_id)');

            return;
        }

        $this->addSql('CREATE TEMPORARY TABLE __temp__tenants AS SELECT name, slug, is_active, created_at, updated_at, id FROM tenants');
        $this->addSql('DROP TABLE tenants');
        $this->addSql('CREATE TABLE tenants (name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, site_id VARCHAR(32) NOT NULL)');
        // Restore site_id from the tenant's own default workspace (the one whose
        // id is lowest, i.e. the first workspace ever created for that tenant).
        $this->addSql('INSERT INTO tenants (name, slug, is_active, created_at, updated_at, id, site_id) SELECT t.name, t.slug, t.is_active, t.created_at, t.updated_at, t.id, (SELECT w.site_id FROM workspaces w WHERE w.tenant_id = t.id ORDER BY w.id ASC LIMIT 1) FROM __temp__tenants t');
        $this->addSql('DROP TABLE __temp__tenants');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B8FC96BB989D9B62 ON tenants (slug)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B8FC96BBF6BD1646 ON tenants (site_id)');
    }
}
