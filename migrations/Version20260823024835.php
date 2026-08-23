<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260823024835 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__monitors AS SELECT tenant_id, name, type, target, interval, expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id FROM monitors');
        $this->addSql('DROP TABLE monitors');
        $this->addSql('CREATE TABLE monitors (tenant_id INTEGER NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, target VARCHAR(255) NOT NULL, interval INTEGER NOT NULL, expected_status INTEGER DEFAULT NULL, regex_check VARCHAR(255) DEFAULT NULL, smtp_username VARCHAR(255) DEFAULT NULL, smtp_password_encrypted CLOB DEFAULT NULL, smtp_secure VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, public_id VARCHAR(36) NOT NULL)');
        // hex(randomblob(N)) is evaluated per output row here (a real SELECT), giving every
        // pre-existing monitor its own unique public_id instead of one shared value.
        $this->addSql("INSERT INTO monitors (tenant_id, name, type, target, interval, expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id, public_id) SELECT tenant_id, name, type, target, interval, expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id, lower(hex(randomblob(4)) || '-' || hex(randomblob(2)) || '-' || hex(randomblob(2)) || '-' || hex(randomblob(2)) || '-' || hex(randomblob(6))) FROM __temp__monitors");
        $this->addSql('DROP TABLE __temp__monitors');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_863DAD3DB5B48B91 ON monitors (public_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__monitors AS SELECT tenant_id, name, type, target, "interval", expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id FROM monitors');
        $this->addSql('DROP TABLE monitors');
        $this->addSql('CREATE TABLE monitors (tenant_id INTEGER NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, target VARCHAR(255) NOT NULL, "interval" INTEGER NOT NULL, expected_status INTEGER DEFAULT NULL, regex_check VARCHAR(255) DEFAULT NULL, smtp_username VARCHAR(255) DEFAULT NULL, smtp_password_encrypted CLOB DEFAULT NULL, smtp_secure VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->addSql('INSERT INTO monitors (tenant_id, name, type, target, "interval", expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id) SELECT tenant_id, name, type, target, "interval", expected_status, regex_check, smtp_username, smtp_password_encrypted, smtp_secure, created_at, updated_at, id FROM __temp__monitors');
        $this->addSql('DROP TABLE __temp__monitors');
    }
}
