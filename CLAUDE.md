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
No process supervisor (systemd/NSSM/Docker) is wired up for local dev — `messenger:consume` must be started manually when you need checks to actually run end-to-end. In production this is handled instead by `zenstruck/schedule-bundle` via a single cron entry — see "Messenger / scheduling flow" below.

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
- Foreign-key-style fields (`Monitor::$workspaceId`, `AlertRule::$monitorId`, `UserCredentials::$identityId`, etc.) have a getter only — immutable after construction.
- **Every relationship in the whole schema is a bare `int` FK column — there are zero Doctrine associations (`many-to-one`, etc.) anywhere**, including the `Identity`↔`UserCredentials`↔`Tenant`↔`TenantMembership`↔`Workspace` cluster. This was a deliberate choice (see "Auth & multi-tenancy & workspaces" below) to keep entities from ever holding object references to each other; cross-entity lookups always go through a repository interface. Don't introduce the first association without discussing it — it'd be a real architectural departure.

### DTOs (`src/Domain/DTO/`)
Two distinct roles, easy to tell apart by shape:
- **Input DTOs** (`MonitorInputDto`, `AlertRuleInputDto`, ...): mutable public properties, `Symfony\Component\Validator\Constraints` attributes, filled field-by-field in the controller from `$request->request->get(...)`, validated via injected `ValidatorInterface`.
- **Output DTOs** (`MonitorDto`, `CheckResultDto`, ...): constructor-promoted `public readonly` properties mirroring the entity, plus a `public static function fromEntity(Entity $e): self` factory.

### Repositories
`Domain/Repository/XRepositoryInterface` (small: `find`, a couple of entity-specific finders, `save`, `delete`) + `Infrastructure/Persistence/Repository/DoctrineXRepository`. Single-implementation interfaces autowire automatically — `config/services.yaml` only gets an explicit entry when something non-trivial is needed (env-derived constructor args, or decoration). `MonitorRepositoryInterface` is the one decorated case: it's bound to `CachedMonitorRepository`, which wraps `DoctrineMonitorRepository` with a PSR-6 cache-aside layer (`monitor_{id}`, `active_monitors`, `active_monitors_workspace_{id}` keys) and falls back to the delegate on any cache failure.

### Messenger / scheduling flow
- Transport is Doctrine (`MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0` in `.env`), i.e. a SQL table (`messenger_messages`), not Redis.
- `App\Schedule` (`#[AsSchedule]`) declares a recurring `DispatchChecksMessage` every minute → its handler calls `Infrastructure\Scheduler\DispatchDueChecksService::dispatchDueChecks()`, which is the single source of truth for "which monitors are due" and dispatches `UptimeCheckMessage`/`SmtpCheckMessage` for them. `Presentation\Command\DispatchChecksCommand` (`app:dispatch-checks`) calls the *same* service — it exists as a manual/cron-triggerable entry point, not a duplicate implementation.
- `UptimeCheckMessage`/`SmtpCheckMessage` are handled by thin handlers that just call `RunUptimeCheckUseCase`/`RunSmtpCheckUseCase` — the actual ping/SMTP-send logic lives in the use case, not the handler.
- `AnalyticsEventMessage` (from `POST /api/event`) is the original async path in this codebase — writes are batched off the request thread via a handler doing a plain `Connection::insert()` (no ORM entity for analytics — see Gotchas).
- Nothing dispatches automatically without a running worker: `messenger:consume scheduler_default async` must be running for the scheduler tick *and* the resulting check messages to actually process.
- **In production**, that worker (plus `app:poll-imap`) is kept alive without a real process supervisor via `zenstruck/schedule-bundle`: `src/Infrastructure/Scheduler/AppScheduleBuilder.php` (auto-registered — implementing `ScheduleBuilder` is enough, no `services.yaml` entry) declares both as bounded, `withoutOverlapping()`-guarded tasks (`messenger:consume scheduler_default async --time-limit=55`, `app:poll-imap`), each `everyMinute()`. Both run as real OS subprocesses (`Schedule::addProcess()`, not `addCommand()`) rather than in-process — zenstruck's in-process command runner shares one `Application`/container across every due task in a single `schedule:run` invocation, so a fatal Doctrine error in `messenger:consume` (closing the EntityManager) would otherwise take `app:poll-imap` down with it; separate processes keep them isolated. The server needs exactly **one** cron entry — `* * * * * php bin/console schedule:run --env=prod` — instead of one raw crontab line per task; `schedule:run` figures out what's due and runs it. This bundle is deliberately *not* used to replace `App\Schedule`'s own internal 1-minute tick (that still owns deciding *which monitors* are due, via `DispatchDueChecksService`) — it only keeps the consumer process alive so that tick gets processed at all. `symfony/lock` (installed alongside, `LOCK_DSN=flock` by default — fine for a single-server deploy) backs `withoutOverlapping()`.

### Alerting
`AlertDecisionService` (`Domain/Service`) is a pure function of (rules, active/last-triggered alert state, current failure status, consecutive failures, latency, now) → trigger/resolve/none decisions, including cooldown/dedup — it has no I/O and is unit-tested in isolation. Sending is a separate concern: `NotificationSenderInterface`/`NotificationSender` dispatches by `AlertRule::$channel` (`email`/`slack`/`telegram`), each failing silently to avoid taking down the worker. Keep this three-way split (detect → decide → notify) when touching alerting logic.

### Auth & multi-tenancy & workspaces
Central anchor is `Identity` (email/verification/enabled/last-login only — **no password**). Satellites hang off it by bare-int FK, each with one responsibility: `UserCredentials` (password hash, isolated so secrets never touch the identity table), `Tenant` (the billing/membership org), `TenantMembership` (join table, `role` field, unique on `(tenant_id, identity_id)`). A `Tenant` can own multiple `Workspace`s — `Workspace` (not `Tenant`) is the scoping boundary for everything functional: `Monitor::$workspaceId` ties a monitor to a workspace, and `Workspace::$siteId` (not `Tenant::$siteId`, which was removed) is the analytics tracking identifier. There is no per-workspace membership/ACL table — access to a workspace is *inherited* from tenant membership: any identity with a `TenantMembership` (any role) on a workspace's owning tenant can access that workspace, exactly the same "any role passes" rule that applied at the tenant level before this split.

Symfony Security is bridged, never implemented on the entity: `Infrastructure/Security/IdentityUser` (`UserInterface`+`PasswordAuthenticatedUserInterface`) *composes* an `Identity`+`UserCredentials` rather than either entity implementing Symfony interfaces directly; `IdentityUserProvider` loads by email through `IdentityRepositoryInterface`/`UserCredentialsRepositoryInterface`. Access control is centralized in `Infrastructure/Security/WorkspaceValueResolver` (a `ValueResolverInterface`, auto-tagged via `autoconfigure: true` — no manual service registration needed): any controller action that type-hints a `Workspace $workspace` argument gets it resolved from the route's `{workspacePublicId}` and access-checked (403 if the current identity has no membership on the owning tenant, 404 if the workspace doesn't exist) before the action body runs. There's no `MonitorVoter` anymore — a monitor belonging to a different workspace than the one resolved in the URL is treated as **404, not 403** (the resolver already proved access to the workspace itself; a mismatched monitor is indistinguishable from "doesn't exist" and 404 avoids confirming it exists elsewhere), checked with a plain `$monitor->getWorkspaceId() === $workspace->getId()` comparison in each action rather than a second DB-backed voter lookup.

`Workspace::$siteId` (16-char hex, `bin2hex(random_bytes(8))`, generated in the constructor, no setter — it's an identity token, not editable data) is the analytics tracking identifier shown in `dashboard/analytics.html.twig`'s embed snippet. One `site_id` per workspace, not per tenant/monitor/website — every workspace-scoped controller gets it from the `Workspace` argument the value resolver already injected, it is never taken from a query param or typed by the user. Every existing `analytics_events` row uses whatever `site_id` a request happened to send, unrelated to this — the two aren't reconciled automatically if old data used a different value. `CurrentTenantResolver` still exists but is now only used for the two places that need "which tenant is this identity acting under" rather than "which workspace": the workspace switcher/list (`WorkspaceRepositoryInterface::findByTenant()`) and `WorkspaceController::new()` (which tenant a newly-created workspace belongs to) — it is no longer called to scope monitors or analytics directly.

`Monitor::$publicId` and `Workspace::$publicId` (both a real `Symfony\Component\Uid\Uuid::v4()`, generated in the constructor, no setter) are what `/workspace/{workspacePublicId}/monitor/{monitorPublicId}`-shaped routes resolve on — the internal auto-increment `id` is intentionally never exposed in a URL (sequential ids are guessable/enumerable). `MonitorRepositoryInterface::findByPublicId()`/`WorkspaceRepositoryInterface::findByPublicId()` are the lookups used by the resolver and controllers; `find(int $id)` still exists on both and is used everywhere *internally* (FK joins, use cases, Messenger messages) — only the outward-facing routes and Twig `path(...)` calls use the public UUIDs. Don't reintroduce `{id: monitor.id}` in a template link; that 404s by design now (a bare int never matches a stored UUID).

The bare `/dashboard` route (name `dashboard_index`) still exists as a workspace-agnostic redirect — it resolves the current tenant's first workspace via `CurrentTenantResolver` + `WorkspaceRepositoryInterface::findByTenant()` and redirects to `workspace_dashboard_index`. It exists solely so the public homepage's "Go to dashboard" CTA (which has no workspace in scope) has somewhere to point; every other dashboard/monitor/analytics route is workspace-scoped from the start.

There's no public registration flow — the only way to create an identity is `app:create-user`. `PasswordHasherInterface` (one-way, for login) and `PasswordEncryptorInterface`/`OpenSslPasswordEncryptor` (reversible AES, for storing a client's SMTP password so the app can log into *their* mail server later) are unrelated and must not be conflated — the second one is architecturally wrong for hashing a user's login password.

### Analytics enrichment (GeoIP / User-Agent)
`GeoIpResolverInterface` (`MaxMindGeoIpResolver`) and `UserAgentParserInterface` (`UaParserUserAgentParser`) follow the usual Domain-interface/Infrastructure-impl split. GeoIP needs a MaxMind GeoLite2-City `.mmdb` file, resolved in `config/services.yaml` as `%env(default:geoip.default_db_path:GEOIP_DB_PATH)%` — falls back to `var/geoip/GeoLite2-City.mmdb` (where the file actually lives; `var/` is gitignored, so it must be placed there manually on any new checkout) unless `GEOIP_DB_PATH` is set to something else. Empty/missing file means lookups silently no-op (all-null), not an error, so the feature degrades gracefully without the file present. `CF-IPCountry`/`X-Country-Code` headers still take priority over GeoIP for `country` when present (Cloudflare's edge-resolved value). Device category (mobile/tablet/desktop) is a simple UA regex classification, not ua-parser's raw device family — browser/OS come from ua-parser itself, which ships its rule set in `vendor/ua-parser/uap-php/resources/regexes.php` (no external download/update needed to function).

### Collect script / public endpoints
`GET /collect/event.js` and `POST /api/event` are deliberately carved out as `PUBLIC_ACCESS` in `config/packages/security.yaml`'s `access_control` (everything else under `/` requires `ROLE_USER`, except the homepage — see below) — they're called anonymously from third-party sites embedding the tracking script, not from logged-in SiteTrack users. If you add another endpoint meant to be called from client sites, it needs the same carve-out or it'll silently redirect to `/login` instead of erroring. Both responses also set `Access-Control-Allow-Origin: *` by hand (no CORS bundle installed) — `event.js` sends its beacon with a `text/plain` body specifically to stay a CORS-"simple" request and avoid a preflight `OPTIONS` request that nothing currently handles.

### Frontend / component conventions
The app has two Twig layout families: `base.html.twig` (authenticated app shell — dashboard nav, `app.user`-gated logout) and `base_public.html.twig` (lean marketing shell for `templates/home/`). They're kept separate rather than unified with more `{% if %}`s because `base.html.twig`'s nav renders unconditionally today, not just its logout block — bolting a public/private toggle onto it would mean conditionally hiding nav links too, not just one block. Both include the shared `templates/components/_stylesheets.html.twig` and `templates/components/_importmap.html.twig` (kept as two separate includes, each dropped into its correctly-named block, after a combined single-file version caused stylesheets/javascripts block overrides to silently lose the other's content) to avoid duplicating that boilerplate.

**Components — one rule, no `symfony/ux-twig-component` (nothing here needs PHP-side logic, would be a heavier addition than the problem requires):** a UI element invoked more than once with different data → a macro in `templates/components/_macros.html.twig` (`brand_mark()`, `button()`, `section_heading()`, `feature_card()`, `stat_card()`, `testimonial_card()`, `pricing_card()`, `faq_item()`, `kpi_delta()`, `tabbed_list()` — all shared across the whole app now, see below). A page section assembled exactly once → a plain `{% include %}` (`templates/home/sections/_*.html.twig`). Don't reach for `{% embed %}` — block-override slots aren't needed by anything built so far, and mixing it in for one case while everything else uses macros/includes breaks the "pick one pattern" rule. `strict_variables: true` is on under `when@test` (`config/packages/twig.yaml`) — always give macro parameters a default value.

**One "Signal" identity across the whole app.** Both the public marketing site (`base_public.html.twig`, `templates/home/**`) and the authenticated dashboard (`base.html.twig`, `templates/dashboard/**`) use the same instrument-panel aesthetic: hairline borders, no shadows, monospace data — built to read as a precision-monitoring product rather than a generic SaaS template. They were deliberately separate identities earlier in this project's history (dashboard kept a stock Tailwind indigo/slate look with DM Sans); unified on request. Status semantics map onto the two-accent palette: `signal` (teal) = up/good/positive, `alert` (copper) = down/bad/failure, `ink-muted` = pending/neutral — don't reintroduce green/red/amber Tailwind stock colors for status, they'd clash with the rest of the palette.

- **Color** (`--color-*` in `@theme`, `assets/styles/src.css`): `paper`/`paper-dim` (backgrounds), `ink`/`ink-muted` (text), `line` (hairline borders/dividers), `signal`/`signal-dark` (teal — primary/positive), `alert` (copper — negative/failure state).
- **Type**: `font-display` (Space Grotesk, headlines only), `font-body` (IBM Plex Sans, everything else), `font-mono` (IBM Plex Mono — any numeric/data display: KPI values, latencies, timestamps, table figures, prices, the hero ticker — anything meant to read as an instrument readout). Self-hosted (`.woff2` under `assets/fonts/<family>/`, pulled via a throwaway `npm install @fontsource/<pkg>` then copied out and uninstalled — not a manual download, not a Google Fonts `<link>`, to avoid leaking visitor IPs to a font CDN on the page that pitches cookie-less analytics). DM Sans (the dashboard's original font, self-hosted the same way) was removed entirely once nothing referenced `font-sans` anymore — don't re-add it without a reason; if a genuinely-different third face is ever needed, add it deliberately rather than reaching for the old default.
- **Signature element**: the public hero's scrolling event ticker (`.ticker-track` in `src.css`, pure CSS `@keyframes`, duplicated content for a seamless loop, `prefers-reduced-motion` respected) — illustrative check/pageview events, not real data.

`assets/styles/src.css` (Tailwind CLI's build *input*) is excluded from AssetMapper's scan via `excluded_patterns` in `config/packages/asset_mapper.yaml` — without that, AssetMapper tries to resolve `@import "tailwindcss"` as a literal file and throws a 500 on unrelated asset requests. Only the compiled `assets/styles/app.css` is ever served.

**Icons**: `symfony/ux-icons`, Heroicons set — `{{ ux_icon('heroicons:bolt', {class: 'w-5 h-5'}) }}`. The dashboard's monitor-type emoji (🌐📧) were replaced with heroicons (`heroicons:globe-alt`, `heroicons:envelope`) as part of the Signal unification, matching the icon language already used on the public site's feature cards. The 📡 favicon data-URI in both `base.html.twig` and `base_public.html.twig` is left as-is (browser tab icon only, not in-page UI).

**Stimulus**: `symfony/stimulus-bundle`, controllers at `assets/controllers/<name>_controller.js` → `data-controller="<name>"`, auto-discovered (no manual registration needed). See `accordion_controller.js` (used by the FAQ macro) for the pattern: one `data-controller` instance per collapsible item, not one controller indexing a list — Stimulus scopes targets to the nearest ancestor, so this needs no index bookkeeping. `aria-expanded` is read/written on the trigger `<button>` itself, not tracked separately.

**Turbo** (`symfony/ux-turbo`, `@hotwired/turbo` imported in `assets/app.js`) is active app-wide — Turbo Drive intercepts internal link clicks and form submissions, replacing the `<body>` via AJAX instead of a full page load. **This means a global inline `<script>` that defines a function and calls it on load will not (re)run on a Turbo-driven navigation to that page** — inline scripts inserted via a Turbo render don't execute, only the initial hard load runs them. `monitor_type_controller.js` (toggling HTTP/SMTP fields on `dashboard/new.html.twig`) replaced exactly this kind of inline script for that reason — it's not a style preference, it was broken under Turbo otherwise. Any future page-specific interactive behavior needs a real Stimulus controller (which reconnects correctly on every Turbo navigation via its own lifecycle), not an inline `<script>`.

**Routing note**: `/` is the public homepage (`homepage` route, `HomeController`) and the authenticated app lives under `/workspace/{workspacePublicId}/...` (`workspace_dashboard_index`, `workspace_availability_index`, `workspace_monitor_*`, `workspace_analytics_*` — see "Auth & multi-tenancy & workspaces" above). `security.yaml`'s `access_control` has an exact-match `{ path: ^/$, roles: PUBLIC_ACCESS }` rule *before* the `{ path: ^/, roles: ROLE_USER }` catch-all — the catch-all is untouched (still covers everything under `/workspace/`, plus the bare `/dashboard` redirect), only an exact-path exception was added ahead of it. The homepage renders for both anonymous and logged-in visitors (header CTA changes based on `app.user`, nothing redirects) — there's no public registration route yet, so every "Get started" CTA currently points at `app_login`.

### Gotchas
- **`analytics_events` schema changes need a hand-written migration, not `doctrine:migrations:diff`.** Since it's outside the ORM's view (see below), diff never sees it. When adding a column, generate a blank migration (`doctrine:migrations:generate`) and write the `ALTER TABLE` by hand — see `migrations/Version20260823004735.php` for the pattern, and remember to apply it to both `var/data.db` and (`APP_ENV=test`) `var/test_data.db`.
- **`analytics_events` and `analytics_rollups_hourly` are not Doctrine entities.** They're written/read via raw `Doctrine\DBAL\Connection` query builders (`AnalyticsEventMessageHandler`, `DashboardController::analytics()`), not the ORM. `config/packages/doctrine.yaml` has `schema_filter: '~^(?!analytics_)~'` specifically so `doctrine:migrations:diff` doesn't see them as "unmanaged" and propose dropping them — this has actually happened once during development. If you ever map these as real entities, remove the filter and generate a proper migration for them at that point, not before.
- The app's real database is SQLite (`var/data.db`, `var/test_data.db`) despite `compose.yaml` defining a Postgres service that isn't currently used by the running app.
- `migrations/` had zero files until the auth work in this project's history — the SQLite schema before that point was created ad hoc (`doctrine:schema:create`/diff), not from a migration history. Don't assume every table has a corresponding migration.
- **If `bin/console asset-map:compile` is ever run, `public/assets/` (gitignored) becomes a frozen static snapshot that AssetMapper prefers over live dev-mode resolution — every subsequent CSS/JS edit is silently ignored by the dev server until that directory is deleted.** Hit this after a CSS redesign where `npm run build` succeeded and the file on disk was correct, but the browser kept getting the pre-redesign styles because a stale `public/assets/manifest.json` from an earlier compile was shadowing it. There's no legitimate reason to run `asset-map:compile` in this project's dev workflow (`php -S ... router.php` serves `assets/` dynamically); if a future asset change doesn't seem to take effect, check for `public/assets/` before assuming a build or cache problem.
