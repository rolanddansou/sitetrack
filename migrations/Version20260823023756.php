<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260823023756 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Front-loaded into Version20260822235943's MySQL branch — see that
        // migration's docblock for why.
        $this->skipIf($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'tenants.site_id already exists on MySQL (created by the baseline migration).');

        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__tenants AS SELECT name, slug, is_active, created_at, updated_at, id FROM tenants');
        $this->addSql('DROP TABLE tenants');
        $this->addSql('CREATE TABLE tenants (name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, site_id VARCHAR(32) NOT NULL)');
        // hex(randomblob(8)) is evaluated once per output row here (a real SELECT), unlike an
        // ALTER TABLE ADD COLUMN ... DEFAULT expression, which SQLite computes once for all rows.
        $this->addSql('INSERT INTO tenants (name, slug, is_active, created_at, updated_at, id, site_id) SELECT name, slug, is_active, created_at, updated_at, id, hex(randomblob(8)) FROM __temp__tenants');
        $this->addSql('DROP TABLE __temp__tenants');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B8FC96BB989D9B62 ON tenants (slug)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B8FC96BBF6BD1646 ON tenants (site_id)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Handled by the baseline migration on MySQL.');

        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__tenants AS SELECT name, slug, is_active, created_at, updated_at, id FROM tenants');
        $this->addSql('DROP TABLE tenants');
        $this->addSql('CREATE TABLE tenants (name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO tenants (name, slug, is_active, created_at, updated_at, id) SELECT name, slug, is_active, created_at, updated_at, id FROM __temp__tenants');
        $this->addSql('DROP TABLE __temp__tenants');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B8FC96BB989D9B62 ON tenants (slug)');
    }
}
