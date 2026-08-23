<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260822235944 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE identities (email VARCHAR(255) NOT NULL, email_verified BOOLEAN NOT NULL, email_verified_at DATETIME DEFAULT NULL, is_enabled BOOLEAN NOT NULL, last_login_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FA392CE8E7927C74 ON identities (email)');
        $this->addSql('CREATE TABLE tenant_memberships (tenant_id INTEGER NOT NULL, identity_id INTEGER NOT NULL, role VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_identity ON tenant_memberships (tenant_id, identity_id)');
        $this->addSql('CREATE TABLE tenants (name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B8FC96BB989D9B62 ON tenants (slug)');
        $this->addSql('CREATE TABLE user_credentials (identity_id INTEGER NOT NULL, password_hash VARCHAR(255) NOT NULL, two_factor_enabled BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_531EE19BFF3ED4A8 ON user_credentials (identity_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__monitors AS SELECT user_id, name, type, target, interval, expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id FROM monitors');
        $this->addSql('DROP TABLE monitors');
        $this->addSql('CREATE TABLE monitors (tenant_id INTEGER NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, target VARCHAR(255) NOT NULL, interval INTEGER NOT NULL, expected_status INTEGER DEFAULT NULL, regex_check VARCHAR(255) DEFAULT NULL, smtp_username VARCHAR(255) DEFAULT NULL, smtp_password_encrypted CLOB DEFAULT NULL, smtp_secure VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO monitors (tenant_id, name, type, target, interval, expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id) SELECT user_id, name, type, target, interval, expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id FROM __temp__monitors');
        $this->addSql('DROP TABLE __temp__monitors');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE identities');
        $this->addSql('DROP TABLE tenant_memberships');
        $this->addSql('DROP TABLE tenants');
        $this->addSql('DROP TABLE user_credentials');
        $this->addSql('CREATE TEMPORARY TABLE __temp__monitors AS SELECT tenant_id, name, type, target, "interval", expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id FROM monitors');
        $this->addSql('DROP TABLE monitors');
        $this->addSql('CREATE TABLE monitors (user_id INTEGER NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, target VARCHAR(255) NOT NULL, "interval" INTEGER NOT NULL, expected_status INTEGER DEFAULT NULL, regex_check VARCHAR(255) DEFAULT NULL, smtp_username VARCHAR(255) DEFAULT NULL, smtp_password_encrypted CLOB DEFAULT NULL, smtp_secure VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO monitors (user_id, name, type, target, "interval", expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id) SELECT tenant_id, name, type, target, "interval", expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id FROM __temp__monitors');
        $this->addSql('DROP TABLE __temp__monitors');
    }
}
