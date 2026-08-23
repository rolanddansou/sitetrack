# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

SiteTrack is a Symfony 7.4 / PHP 8.2+ app that bundles two products into one dashboard:

1. **Ops monitoring suite** — HTTP(S) uptime checks (status code + response time + optional regex on body), SMTP deliverability checks (send a token-bearing test email, poll a central IMAP mailbox for its arrival, measure delivery time and SPF/DKIM/DMARC/spam score), and configurable alerting (per-monitor rules with cooldown/dedup, delivered over email/Slack/Telegram).
2. **Cookie-less web analytics** — a public tracking script served at `/collect/event.js` (`Presentation\Controller\CollectController`, source in `assets/collect/event.js`) that client sites embed via `<script src="…/collect/event.js" data-site="…">`. It auto-fires pageviews (SPA-aware), captures UTM params, and exposes `window.sitetrack.track(name, props)` for custom events. POSTs go to `/api/event` (`AnalyticsApiController`, rate-limited per `site_id`), which enriches the payload server-side — GeoIP (country/region/city via `GeoIpResolverInterface`) and User-Agent parsing (device/browser/os via `UserAgentParserInterface`) — before dispatching an async `AnalyticsEventMessage` that lands in `analytics_events`.

The repo is tracked in git (`origin/master`) and `node_modules`/`.idea` are gitignored (and were retroactively untracked) — don't assume they need special handling beyond that.

## Commands

**Install**
```bash
composer install
npm install
```

**Run the dev server** (no Docker container is used for the app itself — DATABASE_URL in `.env` points at SQLite, not the Postgres service in `compose.yaml`)
```bash
php -S 127.0.0.1:8000 -t public public/router.php
```
The trailing `public/router.php` matters: without a router script, PHP's built-in server 404s directly (never reaching `index.php`) for any path with a file-like extension that doesn't exist on disk — e.g. `/collect/event.js`. Extension-less routes (`/`, `/login`, `/monitor/1`) work either way because the built-in server falls back to `index.php` for those automatically; this router.php closes the gap for everything else. It's dev-only — a real Apache/Nginx/FPM deployment doesn't need it.

**Build CSS** (Tailwind v4 via CLI, not Webpack Encore — JS is served through AssetMapper/importmap)
```bash
npm run build   # one-shot
npm run watch   # rebuild on change
```

**Tests**
```bash
php bin/phpunit                                                  # full suite
php bin/phpunit tests/Unit/Domain/UseCase/RunUptimeCheckUseCaseTest.php   # single file
php bin/phpunit --filter=testIndexPageLoads                      # single test
```
Functional tests run against `var/test_data.db` (APP_ENV=test, forced by `phpunit.dist.xml`). There's no fixtures bundle — tests that need persisted rows create them directly through repository interfaces pulled from `static::getContainer()` and clean up in `tearDown()` (see `tests/Functional/Controller/DashboardControllerTest.php`).

**Always add a permanent test for any new service, use case, repository, or controller behavior** — under `tests/Unit/...` (mock the interfaces, no kernel — the default for anything in `Domain/`) or `tests/Functional/...` (real kernel/DB via `static::getContainer()` — for controllers and end-to-end wiring), mirroring the existing structure. A manual/throwaway verification (a scratch script, an ad hoc `bin/console` check, a temporary test file deleted after confirming it passes) is fine to build confidence while implementing, but isn't a substitute — it leaves nothing to catch a regression next time the code changes. See `tests/Unit/Infrastructure/GeoIp/MaxMindGeoIpResolverTest.php` for the pattern when a class wraps a real external resource (a file, a library) rather than an injected interface: it skips (not fails) the assertions that need the real `var/geoip/GeoLite2-City.mmdb` when that file isn't present, so the suite stays green on a fresh checkout without it, while still exercising real behavior wherever the file exists.

**Syntax/schema checks** (no phpstan/psalm/cs-fixer installed — `php -l` and Doctrine's own validator are the available checks)
```bash
php -l path/to/File.php
php bin/console doctrine:schema:validate
```

**Database / migrations**
```bash
php bin/console doctrine:migrations:diff       # generate a migration from entity/XML changes
php bin/console doctrine:migrations:migrate     # apply
APP_ENV=test php bin/console doctrine:migrations:migrate   # apply to the test DB too — it's a separate SQLite file, migrate both when the schema changes
```
**Always read a generated migration before applying it.** `doctrine:migrations:diff` doesn't know about the two `analytics_*` tables (see Gotchas below) and will propose dropping them if the `schema_filter` in `config/packages/doctrine.yaml` is ever removed or narrowed.

**App-specific console commands**
```bash
php bin/console app:create-user <email> [password] [--tenant-name=...]  # bootstrap: creates Tenant+Identity+UserCredentials+owner TenantMembership
php bin/console app:create-test-users [--count=3] [--prefix=test] [--password=...]  # bulk-creates N throwaway users, each in their own tenant (testN@example.test) — for local cross-tenant testing, not production
php bin/console app:dispatch-checks       # finds due monitors, dispatches UptimeCheckMessage/SmtpCheckMessage
php bin/console app:poll-imap [--daemon]  # polls the SMTP-verification IMAP mailbox
php bin/console messenger:consume scheduler_default async -vv   # run the recurring scheduler + process the resulting check/analytics messages
```
No process supervisor (systemd/NSSM/Docker) is wired up yet for the Messenger worker — this was deliberately deferred. `messenger:consume` must be started manually when you need checks to actually run end-to-end.

## Architecture

### Layering
`src/` is split hexagonal-style:
- **`Domain/`** — `Entity` (plain PHP, framework-agnostic), `DTO`, `Repository` (interfaces only), `Service` (interfaces only), `UseCase` (orchestration, depends only on the interfaces above).
- **`Infrastructure/`** — `Persistence/Repository` (Doctrine implementations), `Security`, `Mailer`, `Imap`, `Notification`, `HttpClient`, `Queue` (Messenger messages/handlers), `Scheduler`.
- **`Presentation/`** — `Controller`, `Command`.

### Entity conventions (matters when adding a new one)
- Plain classes, `declare(strict_types=1)`, **no Doctrine attributes** — mapping lives entirely in `config/doctrine/*.orm.xml`, one file per entity.
- Classic constructor bodies (`$this->x = $x;`), not promoted properties — this is deliberate and differs from the Infrastructure layer, where Doctrine repositories *do* use constructor promotion (`private EntityManagerInterface $entityManager`). Match whichever convention the file you're in already uses.
- `?int $id = null` is always the first property, with `getId()`/`setId()`.
- Foreign-key-style fields (`Monitor::$tenantId`, `AlertRule::$monitorId`, `UserCredentials::$identityId`, etc.) have a getter only — immutable after construction.
- **Every relationship in the whole schema is a bare `int` FK column — there are zero Doctrine associations (`many-to-one`, etc.) anywhere**, including the newer `Identity`↔`UserCredentials`↔`Tenant`↔`TenantMembership` cluster. This was a deliberate choice (see "Auth & multi-tenancy" below) to keep entities from ever holding object references to each other; cross-entity lookups always go through a repository interface. Don't introduce the first association without discussing it — it'd be a real architectural departure.

### DTOs (`src/Domain/DTO/`)
Two distinct roles, easy to tell apart by shape:
- **Input DTOs** (`MonitorInputDto`, `AlertRuleInputDto`, ...): mutable public properties, `Symfony\Component\Validator\Constraints` attributes, filled field-by-field in the controller from `$request->request->get(...)`, validated via injected `ValidatorInterface`.
- **Output DTOs** (`MonitorDto`, `CheckResultDto`, ...): constructor-promoted `public readonly` properties mirroring the entity, plus a `public static function fromEntity(Entity $e): self` factory.

### Repositories
`Domain/Repository/XRepositoryInterface` (small: `find`, a couple of entity-specific finders, `save`, `delete`) + `Infrastructure/Persistence/Repository/DoctrineXRepository`. Single-implementation interfaces autowire automatically — `config/services.yaml` only gets an explicit entry when something non-trivial is needed (env-derived constructor args, or decoration). `MonitorRepositoryInterface` is the one decorated case: it's bound to `CachedMonitorRepository`, which wraps `DoctrineMonitorRepository` with a PSR-6 cache-aside layer (`monitor_{id}`, `active_monitors`, `active_monitors_tenant_{id}` keys) and falls back to the delegate on any cache failure.

### Messenger / scheduling flow
- Transport is Doctrine (`MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0` in `.env`), i.e. a SQL table (`messenger_messages`), not Redis.
- `App\Schedule` (`#[AsSchedule]`) declares a recurring `DispatchChecksMessage` every minute → its handler calls `Infrastructure\Scheduler\DispatchDueChecksService::dispatchDueChecks()`, which is the single source of truth for "which monitors are due" and dispatches `UptimeCheckMessage`/`SmtpCheckMessage` for them. `Presentation\Command\DispatchChecksCommand` (`app:dispatch-checks`) calls the *same* service — it exists as a manual/cron-triggerable entry point, not a duplicate implementation.
- `UptimeCheckMessage`/`SmtpCheckMessage` are handled by thin handlers that just call `RunUptimeCheckUseCase`/`RunSmtpCheckUseCase` — the actual ping/SMTP-send logic lives in the use case, not the handler.
- `AnalyticsEventMessage` (from `POST /api/event`) is the original async path in this codebase — writes are batched off the request thread via a handler doing a plain `Connection::insert()` (no ORM entity for analytics — see Gotchas).
- Nothing dispatches automatically without a running worker: `messenger:consume scheduler_default async` must be running for the scheduler tick *and* the resulting check messages to actually process.

### Alerting
`AlertDecisionService` (`Domain/Service`) is a pure function of (rules, active/last-triggered alert state, current failure status, consecutive failures, latency, now) → trigger/resolve/none decisions, including cooldown/dedup — it has no I/O and is unit-tested in isolation. Sending is a separate concern: `NotificationSenderInterface`/`NotificationSender` dispatches by `AlertRule::$channel` (`email`/`slack`/`telegram`), each failing silently to avoid taking down the worker. Keep this three-way split (detect → decide → notify) when touching alerting logic.

### Auth & multi-tenancy
Central anchor is `Identity` (email/verification/enabled/last-login only — **no password**). Satellites hang off it by bare-int FK, each with one responsibility: `UserCredentials` (password hash, isolated so secrets never touch the identity table), `Tenant` (workspace), `TenantMembership` (join table, `role` field, unique on `(tenant_id, identity_id)`). `Monitor::$tenantId` ties monitors to a workspace, not an individual.

Symfony Security is bridged, never implemented on the entity: `Infrastructure/Security/IdentityUser` (`UserInterface`+`PasswordAuthenticatedUserInterface`) *composes* an `Identity`+`UserCredentials` rather than either entity implementing Symfony interfaces directly; `IdentityUserProvider` loads by email through `IdentityRepositoryInterface`/`UserCredentialsRepositoryInterface`. Access control on individual monitors goes through `MonitorVoter` (attribute `MONITOR_ACCESS`), checked via `denyAccessUnlessGranted()` in `DashboardController` — cross-tenant access returns 403, not a silent empty result.

`Tenant::$siteId` (16-char hex, `bin2hex(random_bytes(8))`, generated in the constructor, no setter — it's an identity token, not editable data) is the analytics tracking identifier shown in `dashboard/analytics.html.twig`'s embed snippet. One `site_id` per tenant, not per monitor/website — `DashboardController::analytics()` resolves it from the current tenant (`currentTenantId()` → `TenantRepositoryInterface::find()`), it is never taken from a query param or typed by the user. Every existing `analytics_events` row uses whatever `site_id` a request happened to send, unrelated to this — the two aren't reconciled automatically if old data used a different value.

`Monitor::$publicId` (a real `Symfony\Component\Uid\Uuid::v4()`, generated in the constructor, no setter) is what `/monitor/{publicId}` routes on — the internal auto-increment `id` is intentionally never exposed in a URL (sequential ids are guessable/enumerable). `MonitorRepositoryInterface::findByPublicId()` is the lookup used by `show()`/`delete()`/`newRule()`; `find(int $id)` still exists and is used everywhere *internally* (FK joins in `checks_results`/`alert_rules`/`smtp_tests`, use cases, Messenger messages) — only the outward-facing routes and Twig `path(...)` calls use `publicId`. Don't reintroduce `{id: monitor.id}` in a template link; that 404s by design now (a bare int never matches a stored UUID).

There's no public registration flow — the only way to create an identity is `app:create-user`. `PasswordHasherInterface` (one-way, for login) and `PasswordEncryptorInterface`/`OpenSslPasswordEncryptor` (reversible AES, for storing a client's SMTP password so the app can log into *their* mail server later) are unrelated and must not be conflated — the second one is architecturally wrong for hashing a user's login password.

### Analytics enrichment (GeoIP / User-Agent)
`GeoIpResolverInterface` (`MaxMindGeoIpResolver`) and `UserAgentParserInterface` (`UaParserUserAgentParser`) follow the usual Domain-interface/Infrastructure-impl split. GeoIP needs a MaxMind GeoLite2-City `.mmdb` file, resolved in `config/services.yaml` as `%env(default:geoip.default_db_path:GEOIP_DB_PATH)%` — falls back to `var/geoip/GeoLite2-City.mmdb` (where the file actually lives; `var/` is gitignored, so it must be placed there manually on any new checkout) unless `GEOIP_DB_PATH` is set to something else. Empty/missing file means lookups silently no-op (all-null), not an error, so the feature degrades gracefully without the file present. `CF-IPCountry`/`X-Country-Code` headers still take priority over GeoIP for `country` when present (Cloudflare's edge-resolved value). Device category (mobile/tablet/desktop) is a simple UA regex classification, not ua-parser's raw device family — browser/OS come from ua-parser itself, which ships its rule set in `vendor/ua-parser/uap-php/resources/regexes.php` (no external download/update needed to function).

### Collect script / public endpoints
`GET /collect/event.js` and `POST /api/event` are deliberately carved out as `PUBLIC_ACCESS` in `config/packages/security.yaml`'s `access_control` (everything else under `/` requires `ROLE_USER`, except the homepage — see below) — they're called anonymously from third-party sites embedding the tracking script, not from logged-in SiteTrack users. If you add another endpoint meant to be called from client sites, it needs the same carve-out or it'll silently redirect to `/login` instead of erroring. Both responses also set `Access-Control-Allow-Origin: *` by hand (no CORS bundle installed) — `event.js` sends its beacon with a `text/plain` body specifically to stay a CORS-"simple" request and avoid a preflight `OPTIONS` request that nothing currently handles.

### Frontend / component conventions
The app has two Twig layout families: `base.html.twig` (authenticated app shell — dashboard nav, `app.user`-gated logout) and `base_public.html.twig` (lean marketing shell for `templates/home/`). They're kept separate rather than unified with more `{% if %}`s because `base.html.twig`'s nav renders unconditionally today, not just its logout block — bolting a public/private toggle onto it would mean conditionally hiding nav links too, not just one block. Both include the shared `templates/components/_stylesheets.html.twig` and `templates/components/_importmap.html.twig` (kept as two separate includes, each dropped into its correctly-named block, after a combined single-file version caused stylesheets/javascripts block overrides to silently lose the other's content) to avoid duplicating that boilerplate.

**Components — one rule, no `symfony/ux-twig-component` (nothing here needs PHP-side logic, would be a heavier addition than the problem requires):** a UI element invoked more than once with different data → a macro in `templates/components/_macros.html.twig` (`button()`, `section_heading()`, `feature_card()`, `stat_card()`, `testimonial_card()`, `pricing_card()`, `faq_item()`). A page section assembled exactly once → a plain `{% include %}` (`templates/home/sections/_*.html.twig`). Don't reach for `{% embed %}` — block-override slots aren't needed by anything built so far, and mixing it in for one case while everything else uses macros/includes breaks the "pick one pattern" rule. The card recipe (`bg-white shadow-sm rounded-lg border border-slate-200`) is applied as a raw class string, not a macro — there's nothing to parameterize. `strict_variables: true` is on under `when@test` (`config/packages/twig.yaml`) — always give macro parameters a default value.

**Tailwind theme**: the palette (indigo-600/700, slate-50–900) is stock Tailwind — no `--color-*` tokens added, classes reference stock names directly everywhere. The only `@theme` override in `assets/styles/src.css` is `--font-sans` (DM Sans). Font is self-hosted (`.woff2` files under `assets/fonts/dm-sans/`, sourced via `npm install @fontsource/dm-sans` then copied in — not a manual download), not loaded via a Google Fonts `<link>`, specifically because a CDN font link would leak every visitor's IP to Google on the same page that pitches cookie-less/privacy-first analytics. `assets/styles/src.css` (Tailwind CLI's build *input*) is excluded from AssetMapper's scan via `excluded_patterns` in `config/packages/asset_mapper.yaml` — without that, AssetMapper tries to resolve `@import "tailwindcss"` as a literal file and throws a 500 on unrelated asset requests. Only the compiled `assets/styles/app.css` is ever served.

**Icons**: `symfony/ux-icons`, Heroicons set — `{{ ux_icon('heroicons:bolt', {class: 'w-5 h-5'}) }}`. Existing dashboard emoji (📡🌐📧) are left as-is — this is for new UI only, not a retroactive rewrite.

**Stimulus**: `symfony/stimulus-bundle`, controllers at `assets/controllers/<name>_controller.js` → `data-controller="<name>"`, auto-discovered (no manual registration needed). See `accordion_controller.js` (used by the FAQ macro) for the pattern: one `data-controller` instance per collapsible item, not one controller indexing a list — Stimulus scopes targets to the nearest ancestor, so this needs no index bookkeeping. `aria-expanded` is read/written on the trigger `<button>` itself, not tracked separately.

**Turbo** (`symfony/ux-turbo`, `@hotwired/turbo` imported in `assets/app.js`) is active app-wide — Turbo Drive intercepts internal link clicks and form submissions, replacing the `<body>` via AJAX instead of a full page load. **This means a global inline `<script>` that defines a function and calls it on load will not (re)run on a Turbo-driven navigation to that page** — inline scripts inserted via a Turbo render don't execute, only the initial hard load runs them. `monitor_type_controller.js` (toggling HTTP/SMTP fields on `dashboard/new.html.twig`) replaced exactly this kind of inline script for that reason — it's not a style preference, it was broken under Turbo otherwise. Any future page-specific interactive behavior needs a real Stimulus controller (which reconnects correctly on every Turbo navigation via its own lifecycle), not an inline `<script>`.

**Routing note**: `/` is the public homepage (`homepage` route, `HomeController`) and `/dashboard` is the authenticated app (`dashboard_index` route — moved off `/`). `security.yaml`'s `access_control` has an exact-match `{ path: ^/$, roles: PUBLIC_ACCESS }` rule *before* the `{ path: ^/, roles: ROLE_USER }` catch-all — the catch-all is untouched (still covers `/dashboard`, `/monitor/*`, `/analytics`), only an exact-path exception was added ahead of it. The homepage renders for both anonymous and logged-in visitors (header CTA changes based on `app.user`, nothing redirects) — there's no public registration route yet, so every "Get started" CTA currently points at `app_login`.

### Gotchas
- **`analytics_events` schema changes need a hand-written migration, not `doctrine:migrations:diff`.** Since it's outside the ORM's view (see below), diff never sees it. When adding a column, generate a blank migration (`doctrine:migrations:generate`) and write the `ALTER TABLE` by hand — see `migrations/Version20260823004735.php` for the pattern, and remember to apply it to both `var/data.db` and (`APP_ENV=test`) `var/test_data.db`.
- **`analytics_events` and `analytics_rollups_hourly` are not Doctrine entities.** They're written/read via raw `Doctrine\DBAL\Connection` query builders (`AnalyticsEventMessageHandler`, `DashboardController::analytics()`), not the ORM. `config/packages/doctrine.yaml` has `schema_filter: '~^(?!analytics_)~'` specifically so `doctrine:migrations:diff` doesn't see them as "unmanaged" and propose dropping them — this has actually happened once during development. If you ever map these as real entities, remove the filter and generate a proper migration for them at that point, not before.
- The app's real database is SQLite (`var/data.db`, `var/test_data.db`) despite `compose.yaml` defining a Postgres service that isn't currently used by the running app.
- `migrations/` had zero files until the auth work in this project's history — the SQLite schema before that point was created ad hoc (`doctrine:schema:create`/diff), not from a migration history. Don't assume every table has a corresponding migration.
