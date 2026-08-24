<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline migration — creates every table that predates migration tracking
 * in this project (see CLAUDE.md's "migrations/ had zero files until the
 * auth work..." gotcha). Version20260822235944 (the previous earliest
 * migration) only ALTERs `monitors` (temp-table rebuild renaming user_id ->
 * tenant_id) and assumes it, plus checks_results/alert_rules/alert_events/
 * smtp_tests/analytics_events/analytics_rollups_hourly/messenger_messages,
 * already exist — they were originally created ad hoc via
 * doctrine:schema:create, never captured by a migration. That's harmless
 * for an already-existing/already-migrated database, but makes the full
 * chain unable to bootstrap a database from zero (e.g. CI, or any fresh
 * clone) — this migration closes that gap.
 *
 * Every table/column here was reconstructed from ground truth, not guessed:
 * `monitors` from Version20260822235944's own SELECT list (the exact shape
 * it expects going in); checks_results/alert_rules/alert_events/smtp_tests/
 * messenger_messages from `doctrine:schema:create --dump-sql` against a
 * fresh DB (their current ORM/Messenger-transport shape, safe to use as
 * the original shape since no migration ever touches them afterward);
 * analytics_events/analytics_rollups_hourly from introspecting the
 * already-fully-migrated dev database directly (information_schema),
 * since neither is ORM-mapped (schema_filter excludes them) and no
 * migration creates them either — Version20260823004735 only ADDS columns
 * to analytics_events, confirming its pre-existing base shape is exactly
 * the columns THAT migration doesn't add.
 *
 * Already-migrated databases (this project's dev MySQL/MariaDB, and any
 * SQLite file that already has this schema from before migrations were
 * tracked) must NOT run this migration's SQL — it would collide with
 * tables that already exist in their final shape. Mark it as applied
 * without executing it there:
 *   php bin/console doctrine:migrations:version --add "DoctrineMigrations\Version20260822235943" --no-interaction
 */
final class Version20260822235943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline: create tables that predate migration tracking (monitors in its original user_id shape, checks_results, alert_rules, alert_events, smtp_tests, analytics_events, analytics_rollups_hourly, messenger_messages).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE monitors (user_id INTEGER NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, target VARCHAR(255) NOT NULL, interval INTEGER NOT NULL, expected_status INTEGER DEFAULT NULL, regex_check VARCHAR(255) DEFAULT NULL, smtp_username VARCHAR(255) DEFAULT NULL, smtp_password_encrypted CLOB DEFAULT NULL, smtp_secure VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');

        $this->addSql('CREATE TABLE checks_results (monitor_id INTEGER NOT NULL, status VARCHAR(50) NOT NULL, response_time_ms INTEGER NOT NULL, checked_at DATETIME NOT NULL, error_message CLOB DEFAULT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');

        $this->addSql('CREATE TABLE alert_rules (monitor_id INTEGER NOT NULL, condition_type VARCHAR(100) NOT NULL, threshold INTEGER NOT NULL, channel VARCHAR(50) NOT NULL, recipient VARCHAR(255) NOT NULL, cooldown_minutes INTEGER NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');

        $this->addSql('CREATE TABLE alert_events (rule_id INTEGER NOT NULL, status VARCHAR(50) NOT NULL, triggered_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, notified BOOLEAN NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');

        $this->addSql('CREATE TABLE smtp_tests (monitor_id INTEGER NOT NULL, status VARCHAR(50) NOT NULL, sent_at DATETIME NOT NULL, received_at DATETIME DEFAULT NULL, delivery_time_seconds INTEGER DEFAULT NULL, spam_score DOUBLE PRECISION DEFAULT NULL, spf_passed BOOLEAN DEFAULT NULL, dkim_passed BOOLEAN DEFAULT NULL, dmarc_passed BOOLEAN DEFAULT NULL, error_message CLOB DEFAULT NULL, id VARCHAR(64) NOT NULL, PRIMARY KEY(id))');

        $this->addSql('CREATE TABLE analytics_events (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, site_id VARCHAR(100) NOT NULL, path VARCHAR(255) NOT NULL, referrer VARCHAR(255) DEFAULT NULL, country VARCHAR(10) DEFAULT NULL, session_id VARCHAR(64) NOT NULL, occurred_at DATETIME NOT NULL)');

        $this->addSql('CREATE TABLE analytics_rollups_hourly (site_id VARCHAR(100) NOT NULL, path VARCHAR(255) NOT NULL, referrer VARCHAR(255) DEFAULT NULL, country VARCHAR(10) DEFAULT NULL, period_start DATETIME NOT NULL, views_count INTEGER NOT NULL, visitors_count INTEGER NOT NULL, PRIMARY KEY(site_id, path, period_start))');

        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE monitors');
        $this->addSql('DROP TABLE checks_results');
        $this->addSql('DROP TABLE alert_rules');
        $this->addSql('DROP TABLE alert_events');
        $this->addSql('DROP TABLE smtp_tests');
        $this->addSql('DROP TABLE analytics_events');
        $this->addSql('DROP TABLE analytics_rollups_hourly');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
