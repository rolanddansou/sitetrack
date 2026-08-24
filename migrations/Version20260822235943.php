<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
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
 * chain unable to bootstrap a database from zero (e.g. CI, a fresh clone,
 * or a fresh production database) — this migration closes that gap.
 *
 * SQLite branch: creates the ORIGINAL pre-migration-history shape (monitors
 * with user_id, tenants without site_id, no workspaces table, ...) so the
 * existing incremental chain (Version20260822235944 onward) replays exactly
 * as it always has — reconstructed from ground truth, not guessed: `monitors`
 * from Version20260822235944's own SELECT list (the exact shape it expects
 * going in); checks_results/alert_rules/alert_events/smtp_tests/
 * messenger_messages from `doctrine:schema:create --dump-sql` against a
 * fresh DB (their current ORM/Messenger-transport shape, safe to use as the
 * original shape since no migration ever touches them afterward);
 * analytics_events/analytics_rollups_hourly from introspecting the
 * already-fully-migrated dev database directly (information_schema), since
 * neither is ORM-mapped (schema_filter excludes them) and no migration
 * creates them either.
 *
 * MySQL/MariaDB branch: every real MySQL database this project touches is
 * either (a) completely empty (a fresh production deploy) or (b) already at
 * the final schema with every migration marked executed (this project's dev
 * MariaDB) — there is no real MySQL database anywhere that needs the
 * original pre-refactor shape replayed step by step, and hand-porting each
 * of the 5 original SQLite-only migrations to MySQL (reserved words like
 * `interval`, CLOB/BOOLEAN/AUTOINCREMENT syntax differences, the
 * temp-table-rebuild idiom) is needless surface area for mistakes. So on
 * MySQL this migration instead creates the schema as it stood right before
 * the workspace migrations (Version20260823160000 onward) — i.e. the
 * combined end-state of every one of those 5 migrations — and each of them
 * is skipped on MySQL (skipIf) since their work is front-loaded here. DDL
 * below is not hand-typed: captured from `doctrine:schema:create --dump-sql`
 * and `messenger:setup-transports` run against a throwaway empty MariaDB
 * database on the same server, then discarded.
 * Version20260823160000/160001/160002 (the workspace migrations) already
 * have working, verified MySQL branches — they run unmodified after this.
 *
 * Already-migrated SQLite databases must NOT run this migration — it would
 * collide with tables that already exist. Mark it as applied without
 * executing it there:
 *   php bin/console doctrine:migrations:version --add "DoctrineMigrations\Version20260822235943" --no-interaction
 */
final class Version20260822235943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline: create tables that predate migration tracking (monitors, checks_results, alert_rules, alert_events, smtp_tests, analytics_events, analytics_rollups_hourly, messenger_messages).';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->upMySQL();

            return;
        }

        $this->upSQLite();
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->downMySQL();

            return;
        }

        $this->downSQLite();
    }

    /**
     * End-state of Version20260822235944 + 004735 + 023756 + 024835 +
     * 155922 combined, in MySQL DDL — i.e. the schema right before the
     * workspace migrations (160000+) take over, which already work
     * correctly on MySQL unmodified.
     */
    private function upMySQL(): void
    {
        $this->addSql('CREATE TABLE identities (email VARCHAR(255) NOT NULL, email_verified TINYINT NOT NULL, email_verified_at DATETIME DEFAULT NULL, is_enabled TINYINT NOT NULL, last_login_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_FA392CE8E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE tenant_memberships (tenant_id INT NOT NULL, identity_id INT NOT NULL, role VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, id INT AUTO_INCREMENT NOT NULL, UNIQUE INDEX uniq_tenant_identity (tenant_id, identity_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE tenants (name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, site_id VARCHAR(32) NOT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_B8FC96BB989D9B62 (slug), UNIQUE INDEX UNIQ_B8FC96BBF6BD1646 (site_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE user_credentials (identity_id INT NOT NULL, password_hash VARCHAR(255) NOT NULL, two_factor_enabled TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_531EE19BFF3ED4A8 (identity_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');

        $this->addSql('CREATE TABLE monitors (public_id VARCHAR(36) NOT NULL, tenant_id INT NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, target VARCHAR(255) NOT NULL, `interval` INT NOT NULL, expected_status INT DEFAULT NULL, regex_check VARCHAR(255) DEFAULT NULL, smtp_username VARCHAR(255) DEFAULT NULL, smtp_password_encrypted LONGTEXT DEFAULT NULL, smtp_secure VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_863DAD3DB5B48B91 (public_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE checks_results (monitor_id INT NOT NULL, status VARCHAR(50) NOT NULL, response_time_ms INT NOT NULL, checked_at DATETIME NOT NULL, error_message LONGTEXT DEFAULT NULL, id BIGINT AUTO_INCREMENT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE alert_rules (monitor_id INT NOT NULL, condition_type VARCHAR(100) NOT NULL, threshold INT NOT NULL, channel VARCHAR(50) NOT NULL, recipient VARCHAR(255) NOT NULL, cooldown_minutes INT NOT NULL, id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE alert_events (rule_id INT NOT NULL, status VARCHAR(50) NOT NULL, triggered_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, notified TINYINT NOT NULL, id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE smtp_tests (monitor_id INT NOT NULL, status VARCHAR(50) NOT NULL, sent_at DATETIME NOT NULL, received_at DATETIME DEFAULT NULL, delivery_time_seconds INT DEFAULT NULL, spam_score DOUBLE PRECISION DEFAULT NULL, spf_passed TINYINT DEFAULT NULL, dkim_passed TINYINT DEFAULT NULL, dmarc_passed TINYINT DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, id VARCHAR(64) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');

        // Final shape directly (includes the columns Version20260823004735 would
        // otherwise add, and the index Version20260823155922 would otherwise add
        // — both are skipped on MySQL, see their skipIf()).
        $this->addSql("CREATE TABLE analytics_events (id INT AUTO_INCREMENT NOT NULL, site_id VARCHAR(100) NOT NULL, path VARCHAR(255) NOT NULL, referrer VARCHAR(255) DEFAULT NULL, country VARCHAR(10) DEFAULT NULL, session_id VARCHAR(64) NOT NULL, occurred_at DATETIME NOT NULL, region VARCHAR(100) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, utm_source VARCHAR(255) DEFAULT NULL, utm_medium VARCHAR(255) DEFAULT NULL, utm_campaign VARCHAR(255) DEFAULT NULL, device VARCHAR(20) DEFAULT NULL, browser VARCHAR(50) DEFAULT NULL, browser_version VARCHAR(20) DEFAULT NULL, os VARCHAR(50) DEFAULT NULL, os_version VARCHAR(20) DEFAULT NULL, event_type VARCHAR(20) DEFAULT 'pageview' NOT NULL, event_name VARCHAR(255) DEFAULT NULL, event_props LONGTEXT DEFAULT NULL, INDEX idx_analytics_events_site_occurred (site_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`");
        $this->addSql('CREATE TABLE analytics_rollups_hourly (site_id VARCHAR(100) NOT NULL, path VARCHAR(255) NOT NULL, referrer VARCHAR(255) DEFAULT NULL, country VARCHAR(10) DEFAULT NULL, period_start DATETIME NOT NULL, views_count INT NOT NULL, visitors_count INT NOT NULL, PRIMARY KEY (site_id, path, period_start)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');

        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    private function downMySQL(): void
    {
        $this->addSql('DROP TABLE monitors');
        $this->addSql('DROP TABLE checks_results');
        $this->addSql('DROP TABLE alert_rules');
        $this->addSql('DROP TABLE alert_events');
        $this->addSql('DROP TABLE smtp_tests');
        $this->addSql('DROP TABLE analytics_events');
        $this->addSql('DROP TABLE analytics_rollups_hourly');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE identities');
        $this->addSql('DROP TABLE tenant_memberships');
        $this->addSql('DROP TABLE tenants');
        $this->addSql('DROP TABLE user_credentials');
    }

    private function upSQLite(): void
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

    private function downSQLite(): void
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
