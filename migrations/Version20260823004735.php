<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds geo/device/UTM/custom-event columns to analytics_events.
 *
 * Hand-written, not diff-generated: this table is intentionally excluded
 * from Doctrine's schema comparison (see the `schema_filter` in
 * config/packages/doctrine.yaml) because it's written via raw DBAL, not
 * an ORM entity.
 */
final class Version20260823004735 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add region/city/utm/device/browser/os/event columns to analytics_events';
    }

    public function up(Schema $schema): void
    {
        // Front-loaded into Version20260822235943's MySQL branch — analytics_events
        // is created there directly in its final shape on MySQL.
        $this->skipIf($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'analytics_events already has these columns on MySQL (created by the baseline migration).');

        $this->addSql('ALTER TABLE analytics_events ADD COLUMN region VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN city VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN utm_source VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN utm_medium VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN utm_campaign VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN device VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN browser VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN browser_version VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN os VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN os_version VARCHAR(20) DEFAULT NULL');
        $this->addSql("ALTER TABLE analytics_events ADD COLUMN event_type VARCHAR(20) NOT NULL DEFAULT 'pageview'");
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN event_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN event_props CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Handled by the baseline migration on MySQL.');

        $this->addSql('ALTER TABLE analytics_events DROP COLUMN region');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN city');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN utm_source');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN utm_medium');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN utm_campaign');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN device');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN browser');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN browser_version');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN os');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN os_version');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN event_type');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN event_name');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN event_props');
    }
}
