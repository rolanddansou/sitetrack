<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rebuilds `monitors` to replace `tenant_id` with `workspace_id`, backfilled
 * via the 1:1 tenant->workspace mapping created in Version20260823160000
 * (every tenant has exactly one workspace at this point in the migration
 * chain, so the join is unambiguous).
 */
final class Version20260823160001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rebuild monitors: tenant_id -> workspace_id.';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE monitors ADD workspace_id INT DEFAULT NULL');
            $this->addSql('UPDATE monitors m INNER JOIN workspaces w ON w.tenant_id = m.tenant_id SET m.workspace_id = w.id');
            $this->addSql('ALTER TABLE monitors MODIFY workspace_id INT NOT NULL');
            $this->addSql('ALTER TABLE monitors DROP COLUMN tenant_id');

            return;
        }

        $this->addSql('CREATE TEMPORARY TABLE __temp__monitors AS SELECT tenant_id, name, type, target, interval, expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id, public_id FROM monitors');
        $this->addSql('DROP TABLE monitors');
        $this->addSql('CREATE TABLE monitors (workspace_id INTEGER NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, target VARCHAR(255) NOT NULL, interval INTEGER NOT NULL, expected_status INTEGER DEFAULT NULL, regex_check VARCHAR(255) DEFAULT NULL, smtp_username VARCHAR(255) DEFAULT NULL, smtp_password_encrypted CLOB DEFAULT NULL, smtp_secure VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, public_id VARCHAR(36) NOT NULL)');
        $this->addSql('INSERT INTO monitors (workspace_id, name, type, target, interval, expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id, public_id) SELECT w.id, t.name, t.type, t.target, t.interval, t.expected_status, t.regex_check, t.smtp_username, t.smtp_password_encrypted, t.smtp_secure, t.created_at, t.updated_at, t.id, t.public_id FROM __temp__monitors t JOIN workspaces w ON w.tenant_id = t.tenant_id');
        $this->addSql('DROP TABLE __temp__monitors');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_863DAD3DB5B48B91 ON monitors (public_id)');
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE monitors ADD tenant_id INT DEFAULT NULL');
            $this->addSql('UPDATE monitors m INNER JOIN workspaces w ON w.id = m.workspace_id SET m.tenant_id = w.tenant_id');
            $this->addSql('ALTER TABLE monitors MODIFY tenant_id INT NOT NULL');
            $this->addSql('ALTER TABLE monitors DROP COLUMN workspace_id');

            return;
        }

        $this->addSql('CREATE TEMPORARY TABLE __temp__monitors AS SELECT workspace_id, name, type, target, interval, expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id, public_id FROM monitors');
        $this->addSql('DROP TABLE monitors');
        $this->addSql('CREATE TABLE monitors (tenant_id INTEGER NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, target VARCHAR(255) NOT NULL, interval INTEGER NOT NULL, expected_status INTEGER DEFAULT NULL, regex_check VARCHAR(255) DEFAULT NULL, smtp_username VARCHAR(255) DEFAULT NULL, smtp_password_encrypted CLOB DEFAULT NULL, smtp_secure VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, public_id VARCHAR(36) NOT NULL)');
        $this->addSql('INSERT INTO monitors (tenant_id, name, type, target, interval, expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id, public_id) SELECT w.tenant_id, t.name, t.type, t.target, t.interval, t.expected_status, t.regex_check, t.smtp_username, t.smtp_password_encrypted, t.smtp_secure, t.created_at, t.updated_at, t.id, t.public_id FROM __temp__monitors t JOIN workspaces w ON w.id = t.workspace_id');
        $this->addSql('DROP TABLE __temp__monitors');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_863DAD3DB5B48B91 ON monitors (public_id)');
    }
}
