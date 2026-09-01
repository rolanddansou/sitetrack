<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds screen_width/screen_height to analytics_events.
 *
 * Hand-written, not diff-generated: this table is intentionally excluded
 * from Doctrine's schema comparison (see the `schema_filter` in
 * config/packages/doctrine.yaml) because it's written via raw DBAL, not
 * an ORM entity.
 */
final class Version20260901130730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add screen_width/screen_height columns to analytics_events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN screen_width INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_events ADD COLUMN screen_height INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN screen_width');
        $this->addSql('ALTER TABLE analytics_events DROP COLUMN screen_height');
    }
}
