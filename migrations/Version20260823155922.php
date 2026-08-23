<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a composite index on analytics_events(site_id, occurred_at).
 *
 * Hand-written, not diff-generated: this table is intentionally excluded
 * from Doctrine's schema comparison (see the `schema_filter` in
 * config/packages/doctrine.yaml) because it's written via raw DBAL, not
 * an ORM entity — the Schema API is unavailable here for the same reason
 * (the filter also hides the table from migration-time introspection).
 * Every /analytics query filters on this exact pair of columns, and the
 * table had no index at all. CREATE INDEX syntax is identical across
 * SQLite/MySQL/Postgres; only MySQL's DROP INDEX needs an "ON table"
 * clause, hence the platform check in down().
 */
final class Version20260823155922 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite index on analytics_events(site_id, occurred_at)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_analytics_events_site_occurred ON analytics_events (site_id, occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->addSql($platform instanceof AbstractMySQLPlatform
            ? 'DROP INDEX idx_analytics_events_site_occurred ON analytics_events'
            : 'DROP INDEX idx_analytics_events_site_occurred');
    }
}
