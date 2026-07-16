# Changelog

All notable changes to `pushery/webhooks-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.4] - 2026-07-16

### Fixed

- **The optional operator delivery-log screen no longer runs a full row count on every render.**
  It reads the whole delivery log unscoped, and a `count(*)` over a partitioned table with millions
  of rows does not scale; it now uses simple (previous/next) pagination, which needs no total. The
  tenant-facing dashboard tables are owner-scoped and were never affected.
- **Accessibility: the payload-transform editor now announces to screen readers when the live
  preview recomputes.** Its `aria-live` status region carried a constant string, so it never
  actually fired; it now re-renders on each edit. The Pulse card's failure-rate text was also
  darkened to meet the AA contrast minimum.

## [1.4.3] - 2026-07-16

### Fixed

- **The exponential backoff cap is floored at one second, like the base.** The base delay was
  already floored so a misconfigured `0` could not collapse retries into a zero-delay storm; the
  cap was not, so a `backoff.cap` of `0` would have floored every retry delay to zero regardless of
  the base. Both bounds are now floored.

## [1.4.2] - 2026-07-16

### Fixed

- **On MySQL, the endpoint health window no longer slides with the database session time zone.**
  MySQL converts an offset-bearing timestamp literal into the database's session time zone, so the
  health scorer's window bound — rendered in the PostgreSQL offset form — shifted the 24-hour window
  by that offset against the UTC-naive column. East of UTC the window silently narrowed and dropped
  its oldest hours, so an endpoint that had recently been failing could score a perfect, unearned
  health and never be auto-disabled. The bound is now rendered for the connection's own dialect, and
  the whole window is scored whatever the session zone is.
- **On MySQL, retention pruning no longer deletes rows before their window has closed.** The inbound
  call log and the standalone server delivery log bound their retention cutoff in the same
  session-zone-sensitive way, so a scheduled prune east of UTC removed rows up to the session offset
  early. The cutoff is now bound for the connection's dialect.
- **The self-service payload-transform editor fails not-found for an endpoint the tenant does not
  own, before the authorization check** — so a foreign-but-existing id can no longer be told apart
  from a non-existent one, closing an id-enumeration seam. This matches how every other portal panel
  already scopes a lookup; no legitimate access changes.
- **The spatie backfill import now masks credential-bearing request headers (Authorization, Cookie)
  before writing them to the call log,** exactly as the live receive path does — both now redact
  through one shared component, so they can never disagree on which headers are secret.

## [1.4.1] - 2026-07-16

### Fixed

- **The self-service portal re-checks its authorization gate on every request, not only the first
  one.** Livewire runs `mount()` once; every later interaction is an update request that skips it,
  so a gate authorized in `mount()` alone was replayable — a tenant whose `manage-webhook-endpoints`
  ability was withdrawn mid-session kept being served by the panels it already had open, until it
  reloaded the page. Every mutation was already safe (each carries a row-level policy, and that
  policy re-checks the ability), but a read path with no policy — the endpoint list's refresh
  listener — kept re-rendering the tenant's endpoints against an ability it no longer had. The gate
  now runs in `boot()`, the first hook on both the initial and the update path, for every portal
  panel and the portal page; it answers the same 403 an unauthorized mount always has. No data ever
  crossed a tenant boundary — the panels scope every query to the acting tenant regardless. The
  dashboard was never affected: its gate travels with the route as middleware, which Livewire
  re-applies on update requests.

## [1.4.0] - 2026-07-16

### Added

- **UUID and ULID owner keys.** A webhook subscription's owner may now be keyed by a UUID or a
  ULID, not only a bigint. Set `platform.owner_key_type` (`WEBHOOKS_OWNER_KEY_TYPE`) to `uuid` or
  `ulid` before migrating and the denormalised `owner_id` column is rendered to match across all
  three tables it spans — the subscriptions table, the delivery log and the dashboard rollup — so a
  host whose tenants key by UUID/ULID no longer has to hand-patch the published migrations after
  every `vendor:publish`. The default stays `bigint`, so existing installs are unaffected;
  `subscribe()` rejects an owner whose key does not match the configured type up front, with a clear
  error, instead of failing on the first fan-out. The global (owner-less) row's MySQL rollup
  sentinel follows the type too (the nil UUID / all-zero ULID), keeping operator-mode reads correct
  on every engine. Proven end to end on both PostgreSQL and MySQL.

## [1.3.1] - 2026-07-15

### Fixed

- **On MySQL, Platform delivery-log lifecycle updates silently failed — every delivery stayed
  `pending`, the circuit breaker never tripped, endpoint health never updated.** The lifecycle
  listener locates the delivery row by `(id, created_at)`, and the manager rendered that `created_at`
  key as a PostgreSQL offset literal (`…+00:00`) regardless of engine — which matches zero rows
  against MySQL's UTC-naive `DATETIME(6)` column under strict mode. So on any MySQL deployment of the
  Platform layer the row was never found: outcome columns stayed null, `consecutive_failures` never
  advanced (a dead endpoint was never auto-disabled), and the succeeded/failed events never fired.
  The key is now rendered for the webhook connection's dialect.
- **The SQL dialect now follows the webhook connection, not the application default — the dedicated
  side-car topology worked in name only.** Every runtime query already ran against the connection
  `webhooks.database.connection` points at, but the SQL *dialect* for those queries was chosen from
  the application's default connection. When both are the same engine they agree, so this was
  invisible; but in the documented side-car deployment — an app on one engine keeping the webhook
  tables on a dedicated connection of the other — the dialect was wrong for every runtime path:
  inbound webhooks were never persisted (a MySQL-shaped insert issued against a PostgreSQL side-car),
  the metrics rollup never refreshed, and health/partition maintenance errored. The dialect is now
  resolved from the webhook connection everywhere, and a guard keeps it that way.
- **The optional tdigest percentile extension is now probed on the webhook connection, not the app
  default.** The presence check ran on the application-default connection while the tdigest SQL ran on
  the webhook (side-car) connection — so under a side-car it could either wrongly report the extension
  missing (disabling a supported feature) or pass the check and then fail the query with the exact
  cryptic error the check exists to prevent.
- **`ui.csp_nonce` no longer has to be a config closure that breaks `php artisan config:cache`.** A
  per-request nonce is a closure, and a closure placed in `config/webhooks.php` makes
  `config:cache` (part of a normal production deploy) fail — the exact deploy the CSP audience runs.
  Register the nonce source at runtime instead, `UiTheme::resolveNonceUsing(fn () => Vite::cspNonce())`
  from a service provider; the config value is now a static string only, and a closure left in config
  raises a clear error naming the migration path rather than silently dropping the nonce.
- **The PostgreSQL hourly-rollup buckets stay on whole UTC hours under any database session time
  zone.** The bucket origin was resolved against the session zone, so a sub-hour-offset zone shifted
  every bucket boundary to `:30`, diverging from the MySQL rollup and from the package's own
  epoch-floor fallback. The origin is now pinned to UTC. Affects fresh installs; an existing install
  recreates the materialized view to pick it up.
- **A typo in the `dedupe` driver key is caught at config load instead of silently disabling the
  cache fast path.** `dedupe` was read without validation, so an unrecognised value quietly fell
  through to the database-only path (a performance regression under a retry storm, with no error).
  It is now validated against `redis+db` / `db` and throws on anything else, like every sibling
  config key.

## [1.3.0] - 2026-07-15

### Security

- **⚠ Behaviour change (action required): the dashboard and self-service authorization gates are now
  fail-closed.** `view-webhook-dashboard` and `manage-webhook-endpoints` previously returned `true`
  for **every authenticated user** when the host had not defined a `webhooks.view` / `webhooks.manage`
  ability — so a host that registered the provider but overlooked the ability silently exposed an
  operator surface to all logged-in users. They now **deny** until the host defines the ability
  (`Gate::define('webhooks.view', …)` / `Gate::define('webhooks.manage', …)`; see the README's
  dashboard and self-service sections). A host that relied on the permissive default must add the
  ability to restore access.
- **The self-service health matrix and payload-transform editor now scope at the query.** They loaded
  a subscription by id and authorized only afterwards; a foreign or tampered id is now filtered out at
  the query and fails not-found before any action runs — defence in depth, so a single policy
  regression can no longer be the only guard.

### Added

- **`InboundVerifier` — a verification seam that may do I/O, for providers a signature cannot
  express.** `SignatureScheme::verify()` is a pure function of body, headers and secret — the right
  contract for HMAC dialects, but some providers cannot be verified that way: one that signs nothing
  (authenticity is an authenticated API callback) or verifies through a cert-chain API keyed on a
  webhook ID rather than a secret. A client config may now set `verifier` to a
  `Webhooks\Client\Verification\InboundVerifier` class: container-resolved (so it may hold an HTTP
  client or API credentials), handed the `Request` and `WebhookConfig`, taking precedence over
  `scheme`, and making `secret` optional. Everything after verification — rate limit, dedupe, store,
  dispatch, the 401 path — is unchanged.
- **`dedupe_id` — derive the inbound idempotency key from the body, not only a header.** The receiver
  read the dedupe key exclusively from a configured header, but many providers carry no delivery-id
  header (the id is in the body, or none is sent), so the key stayed `NULL`, a `NULL` never collides
  with the partial-unique index, and dedupe **silently did nothing** for those producers. A client
  config may now set `dedupe_id` to `'header:Name'`, `'body:dotted.path'` (a path into the decoded
  JSON body), or a `Webhooks\Client\Dedupe\DedupeKeyResolver` class the container resolves — evaluated
  after signature verification, so the body is authentic. Unset keeps the previous header behaviour.
- **Signature header names are configurable for every scheme, not just the two first-class ones.**
  `WebhookConfig::scheme()` injected the configured `signature_headers` only into
  `StandardWebhooksScheme` and `Ed25519Scheme`; every other scheme — including the shipped
  `PlainHmacScheme` — kept its hard-coded default header, so a host binding a provider with a
  different header name silently rejected every webhook as malformed. Schemes now opt in via a
  `Webhooks\Core\Signing\AcceptsSignatureHeaders` interface (implemented by `PlainHmacScheme`,
  `GitHubScheme`, `StripeStyleScheme`), and the config injects **only** the header names the host
  explicitly set — an omitted key keeps the scheme's own default, so `GitHubScheme` keeps
  `X-Hub-Signature-256` and is never clobbered by the Standard-Webhooks fallback.
- **`PendingWebhook::dispatch()` now returns the queued `WebhookDeliveryData`.** A Server-only host
  had no delivery row and so nothing to correlate a send against its own log or a later status
  callback. `dispatch()`, `dispatchSync()`, `dispatchIf()` and `dispatchUnless()` now return the
  dispatched `WebhookDeliveryData` (the conditional ones return `null` when nothing is sent), whose
  `messageId` — stable across retries — is the correlation key. Backward-compatible: callers that
  ignored the `void` return are unaffected.
- **Timestamp query scopes on the log models, so a host querying the tables cannot bind a naive,
  silently-wrong timestamp.** Every timestamp column is `timestamptz` (PostgreSQL) or a UTC-naive
  `DATETIME(6)` (MySQL); a plain `->where('created_at', '<', …)` binds a naive literal the database
  resolves against its **session time zone** — unrelated to `app.timezone` and routinely not UTC — so
  the comparison is off by that offset and quietly returns the wrong rows. `WebhookDelivery`,
  `WebhookCall` and `WebhookServerDelivery` now carry `createdBefore()`, `createdAfter()`,
  `createdBetween()` (half-open) and the general `whereTimestamp(column, operator, moment)` scopes,
  plus `WebhookDelivery::pendingSince()`; each binds the instant per dialect.
  `(new WebhookDelivery)->boundTimestamp($moment)` exposes the same offset-correct literal for a raw
  statement. New README section "Querying the tables yourself".
- **Operator dashboard mode — observe the global, owner-less endpoints.** The package supports global
  (owner-less) subscriptions that receive every event, but the dashboard could not show them: every
  read scoped hard to the owner morph pair (which SQL equality never matches against `NULL`). Setting
  `dashboard.operator = true` (`WEBHOOKS_DASHBOARD_OPERATOR`) now scopes the whole dashboard to the
  owner-less rows. It shows *global rows only* — never one tenant's rows to another — to whoever the
  `view-webhook-dashboard` gate admits, so gate that ability to operators.
- **Prefix-wildcard subscriptions (`order.*`).** With `platform.wildcards` on (off by default), a
  subscription may list a prefix wildcard: a concrete `order.line.added` is delivered to subscribers
  of `order.line.added`, `order.line.*` and `order.*` — one prefix per dot boundary. Each arm is still
  an indexed `whereJsonContains`, so the GIN / multi-valued index serves the fan-out unchanged; a
  dot-less type still matches only exactly.
- **`webhooks.schedule.enabled` — opt out of the package's own scheduled maintenance.** A
  DB-per-tenant host must not run partition rolling, secret revocation, the rollup refresh, health
  sweeps and log pruning against the central database only. Setting `webhooks.schedule.enabled =
  false` now makes the package register nothing in the scheduler; the commands are unchanged and the
  host runs them inside its own tenant loop. Defaults to `true`, so a single-database app is
  unaffected.
- **The shipped UI mounts in a host app with its own asset pipeline and a strict CSP.** Two additive
  config options fix both blockers without forking the layout: `ui.assets` names a Blade partial the
  full-page layouts `@include` in `<head>` (your `@vite` tags), and `ui.csp_nonce` (a string or
  per-request callable, e.g. `fn () => Vite::cspNonce()`) puts a nonce on the inline theme script.
  Both default to null. New README section "Embedding in an app with its own asset pipeline and a
  strict CSP".

### Fixed

- **The owner morph-key type is consistent, and a non-integer owner is rejected up front.** The
  `webhook_subscriptions` table created its owner columns with `nullableMorphs()` on PostgreSQL —
  which follows `Schema::defaultMorphKeyType()` — while the delivery-log and dashboard-rollup DDL
  hard-coded `owner_id` as `bigint`. A host that set UUID morph keys got a subscriptions table it
  could populate but a delivery log it could not. The owner columns are now explicitly `bigint`
  everywhere, and `WebhookManager` rejects a non-integer owner key with a clear message at
  `subscribe()` time. Integer-keyed owners (the default) are unaffected.
- **`large_payload` offload no longer defaults its threshold to 0.** `Settings::largePayloadThreshold()`
  fell back to `0` instead of the documented `262144`, so a host that enabled `large_payload` in a
  trimmed config block without an explicit `threshold` would offload **every** delivery payload to
  disk rather than only the large ones. The accessor now carries the documented 256 KiB default.

## [1.2.0] - 2026-07-15

### Added

- **`webhooks:import-spatie-calls` — a one-command backfill from `spatie/laravel-webhook-client`.**
  Adopting the inbound Client layer no longer means starting with an empty log: this artisan
  command copies an existing spatie `webhook_calls` backlog into this package's own table, on
  **PostgreSQL or MySQL**. It maps their columns onto the superset (`name → source`,
  `payload`, `headers`, `exception`), preserves the original timestamps, and is **idempotent** —
  each imported row's key is derived deterministically from its source, so it is safe to re-run and
  a second pass imports nothing new. `--dry-run` reports the counts before writing;
  `--from-table`, `--from-connection`, `--chunk` and `--source` cover a differently-named source
  table, a source database other than the app default, memory-bounded batches over a large
  backlog, and a forced `source` value. Because spatie stored only the parsed payload and never the
  raw received bytes, an imported row carries a **reconstructed, self-consistent** `body_sha256`
  (not the producer's original) and is written in a terminal state — `processed`, or `failed` when
  spatie recorded an exception — so importing months-old history never re-fires a handler's side
  effects. The README's *Coming from spatie* section documents the full flow.

## [1.1.0] - 2026-07-15

### Added

- **MySQL 8.4+ is now a first-class storage engine, alongside PostgreSQL.** The persistent
  layers (Platform, Client, Dashboard and standalone persistence) run on **either**
  PostgreSQL 13+ or MySQL 8.4+, and every guarantee holds identically on both: exact
  percentile numbers, race-free de-duplication, the `body_sha256` byte-fidelity promise, the
  database-enforced `ON DELETE CASCADE` erasure cascade, DST-safe timestamps, and
  case-sensitive identity. What MySQL trades away is storage *optimizations* (O(1)
  partition-drop retention, partial indexes, the optional `tdigest` percentile tier), never
  correctness. Choose the engine your application already runs on — the new **Choosing your
  database** section in the README states the differences, with a tip and a recommendation for
  each. MariaDB is rejected with a clear error (its `JSON` is a text alias and it lacks the
  multi-valued and functional indexes the engine relies on).
- **A dedicated database connection for the package's tables.** Set
  `webhooks.database.connection` (env `WEBHOOKS_DB_CONNECTION`) to keep every webhook table on
  a connection other than the application default — the headline case being a MySQL
  application with a PostgreSQL side-car. The models, migrations and analytics queries all
  resolve the same configured connection, so the package never splits across two databases;
  left unset, everything stays on the application default. `webhooks:preflight` reports the
  resolved connection and, on MySQL, checks it against every requirement.
- **A migration guide for `spatie/laravel-webhook-server` and `spatie/laravel-webhook-client`
  users** — the field mapping onto this package's superset, on MySQL or PostgreSQL.

### Changed

- **Persistence is no longer PostgreSQL-only.** The 1.0.x line documented the storage layer as
  PostgreSQL-only by design; that is now retracted — MySQL 8.4+ is fully supported. Two
  changes are visible to an existing PostgreSQL application on upgrade, both safe: the delivery
  log gains a plain `created_at` index (previously only partial and composite indexes existed;
  the index keeps retention cheap on both engines), and the delivery-log primary key orders by
  a time-ordered UUID, preserving insert locality. Re-publish and review the migrations before
  upgrading a populated database.
- The `Webhooks\Database\PostgresRequirement` guard (reached only by a migration copy published
  from 1.0.0) now names the layer and the ways forward — re-publish for the MySQL schema, point
  at a PostgreSQL connection, or run send-only — instead of pointing only at Neon.

### Fixed

- **Send-only and receive-only apps are now isolated by the configuration gate, not by a data
  convention.** The delivery gate was rebound to the subscription-reading gate unconditionally,
  so a send-only host that set a `subscription_id` delivery-meta key would query the
  `webhook_subscriptions` table — one its configuration never migrated. The rebind now happens
  only while the Platform layer is enabled, so a send-only or receive-only app keeps the open
  gate.

## [1.0.1] - 2026-07-14

### Fixed

- **The package could not be used at all in an application that pins the date class to
  `CarbonImmutable`.** `Date::use(CarbonImmutable::class)` is a common hardening: it makes
  accidental in-place date mutation impossible. Under it, Eloquent hands back a
  `CarbonImmutable`, but `HasZonedTimestamps::asDateTime()` declared the **mutable**
  `Illuminate\Support\Carbon` as its return type — so every timestamp read on every model in
  this package raised
  `TypeError: ... Return value must be of type Illuminate\Support\Carbon, Carbon\CarbonImmutable returned`,
  and `Webhooks::subscribe()` threw on the very first endpoint. The return type is now
  `Carbon\CarbonInterface`, which is the honest contract — the method shifts a timestamp into
  the application's timezone and does not care whether the instance is mutable. Behavior is
  unchanged for an application that does not pin the date class.

  The suite could not see this because the workbench never pinned the date class; it now
  does, in `tests/Feature/ImmutableDatesTest.php`, which fails on the old return type.

## [1.0.0] - 2026-07-13

A ground-up rewrite into an all-in-one, config-gated webhooks toolkit for Laravel —
send, receive, observe and self-serve, with each layer switched on independently. The
delivery and receiving engine is now entirely in-house (no third-party webhook-engine
dependency), Standard Webhooks signatures are the default, and the storage layer is
PostgreSQL-native.

### Added

- **In-house delivery engine (Server layer).** A fluent, immutable `PendingWebhook`
  builder that signs, queues and sends outbound webhooks, with exponential backoff and
  full jitter, Retry-After awareness, per-call timeouts, SSRF-pinned connections, mutual
  TLS, a forward proxy, tags, metadata, and queue/connection selection. Optional
  standalone delivery persistence records every delivery for consumers that send without
  the Platform layer.
- **Standard Webhooks signatures by default** — `webhook-id` / `webhook-timestamp` /
  `webhook-signature` over `{id}.{timestamp}.{rawBody}`, byte-compatible with the
  specification and its official SDKs. Additional dialects: the generic `t=,v1=`
  Stripe-style adapter (the format 0.x sent, so existing consumers keep verifying),
  Stripe, GitHub and plain-HMAC receive adapters; asymmetric Ed25519 (the `v1a` variant)
  with JWKS support for rotating provider keys; zero-downtime secret rotation; and
  optional canonical-JSON signing. Switching on `server.signing.ed25519` signs every
  outbound delivery with the Server's own Ed25519 key, so a receiver holds nothing but a
  public key — and an enabled flag without a key is a hard error, never a silent fall
  back to HMAC. Published interop vectors under `resources/interop` let a third party or
  an other-language port prove byte-for-byte compatibility: the canonical symmetric
  example, a deterministic Ed25519 `v1a` known-answer vector, and negative vectors that
  must fail to verify — each re-checked against the engine by a test, so the published
  contract can never drift.
- **Inbound receiving (Client layer, opt-in).** Verify, de-duplicate, store and queue
  incoming webhooks via the `Route::webhooks()` macro or the controller-less processor:
  `401` on an unverifiable signature, replay protection, two-tier idempotency, raw-body
  capture, per-source rate limiting, header redaction, and event-type-to-handler
  routing. An app verifies its own deliveries with `scheme => 'auto'`.
- **Platform layer.** Endpoint subscriptions and event fan-out
  (`WebhookEvent::dispatch`), an event catalog with optional JSON-Schema payload
  validation, a per-endpoint circuit breaker and rate limit, and a monthly
  range-partitioned delivery log with scheduled partition maintenance and retention.
- **Self-service portal (opt-in).** A tenant-scoped Livewire/WireKit surface where a
  customer manages its own endpoints — list, create/edit, reveal and rotate the signing
  secret, a health matrix, and a payload-transform editor — guarded by a gate and a
  row-level policy so a tenant only ever sees the endpoints it owns.
- **Endpoint health scoring (opt-in).** A 0–100 score per endpoint blended from success
  rate, p95 latency and the consecutive-failure streak, with a refresh command and
  cached columns. When continuous scoring is on, the refresh command is also scheduled
  (cadence configurable via `platform.health.refresh`, default every fifteen minutes) to
  sweep every active endpoint, so an endpoint whose traffic dries up decays to its true
  band instead of freezing on the last score a delivery left it.
- **Per-endpoint payload transforms and versioning (opt-in).** A safe, declarative
  transformer (include / exclude / rename / rewrap plus a stamped version) reshapes the
  body per endpoint before it is signed, so two endpoints on one event can receive
  different, versioned bodies.
- **Observability dashboard (opt-in).** A customer-facing analytics UI over the delivery
  log — KPI cards, hourly activity, latency percentiles, a live delivery queue, top
  events, and a sortable, filterable deliveries table with one-click redelivery — on an
  hourly materialized-view read model with a refresh command, plus an optional
  high-volume percentile path.
- **JSON metrics endpoint (opt-in, `dashboard.expose_json_api`).** Serves the dashboard's
  own read model as JSON at `GET /webhooks/api/metrics?window=24h` — the KPI counts, the
  retry rate, the latency percentiles, the hourly buckets and the busiest event types — so
  a host can drive its own charts, status page or alerting from the same numbers. It runs
  behind the dashboard's middleware and `view-webhook-dashboard` gate, is scoped to the
  acting tenant, validates the window against the configured set (an unsupported one is a
  `422`), and exposes aggregates only — never a delivery row, payload or secret. The route
  is not registered at all while the flag is off.
- **Translatable UI, shipping seven languages** — English, German, Spanish, French, Italian,
  Dutch and Portuguese. Every string the shipped UI puts in
  front of a user is resolved through the `webhooks` translation namespace, across every
  surface: the observability dashboard, the self-service endpoint portal, the publishable
  management stubs (neutral and WireKit) and the Laravel Pulse card. That means headings,
  labels, placeholders, buttons, empty states, toasts, table headers, status and health
  badges, the forms' validation messages, the signing-secret countdown, and the accessible
  names a screen reader announces. Status and health values are translated for display
  only; the persisted value is unchanged. The rendered locale is the host app's, and
  `--tag=webhooks-lang` publishes the files so a host can override any string or add a
  locale. Two tests keep it honest: key parity holds every locale to the same key set, and
  a reference check resolves every key the shipped views and PHP actually ask for — so
  both a missing translation and a misspelled key fail the build instead of quietly
  rendering English, or the raw key, to a reader. Every non-English locale is written in
  the informal register throughout, and each locale's date patterns are authored for its
  own ordering, not just its month names.
- **Operational tooling.** A published egress-IP allowlist command and optional forward
  proxy, an AsyncAPI 3.0 export command, an Ed25519 keypair command, optional full-text
  search over the logs via Laravel Scout, an internal-ops Laravel Pulse card, and a
  dependency-free OpenTelemetry span seam — each off by default.
- **A Tailwind source registration for the shipped screens.** `resources/css/webhooks.css`
  registers the package's views with the host's Tailwind build in one import; the README's
  new "Styling the UI" section documents it, together with WireKit's own (also required)
  source glob and the optional icon set.
- **Dark mode for the package's own layouts,** through `ui.theme` (`auto` / `light` /
  `dark`, `WEBHOOKS_UI_THEME`). It mirrors the reader's system preference by default; a
  pinned theme emits no inline script, which is the escape hatch under a strict CSP.
- **A token-styled, translatable pagination control** (`webhooks::pagination`), rendered by
  every paginating screen in place of the framework default.

### Changed

- The package is now an all-in-one superset spanning sending, receiving, a self-service
  portal and an observability dashboard, replacing the previous send-only product.
- Configuration is reorganized under `core` / `server` / `platform` / `client` /
  `dashboard` (plus `pulse` / `search` / `otel`); the previous flat keys are gone.
- Each layer is gated by its own `enabled` flag: `server.enabled` and `platform.enabled`
  now switch their providers on or off (enabling the Platform layer implies the Server
  engine, since fan-out delivers through it), so a send-only or receive-only app omits
  the machinery and tables it does not use. The two dependencies between the gates —
  Platform implies Server, and the Dashboard reads Platform's delivery log — are stated
  in the README's layer table and inline in the config, where an operator meets them.
- Every `server` setting is now the default for **each** outbound call, not only for
  Platform fan-out: the signing dialect (`core.signing.scheme`), HTTP verb, connect and
  response timeouts, try count, TLS verification, canonicalization, the Retry-After
  policy and the backoff base/cap all seed a `PendingWebhook`, which still overrides any of
  them per delivery.
- `core.egress.enabled` is now a real, fail-closed gate: a configured egress proxy is
  routed through only while the egress layer is switched on. Because a proxy resolves the
  destination host itself, it weakens the SSRF IP pin, so it may not take effect merely
  because a URL was left in the environment.
- The default signature is Standard Webhooks, replacing the earlier single `t=,v1=`
  header. The Stripe-style dialect remains available as a receive adapter.
- The standalone `Webhooks\Signing\SignatureVerifier` helper is gone; verify inbound
  Stripe-style signatures with the `StripeStyleScheme` receive adapter instead.
- The delivery layer no longer depends on any third-party webhook-engine package;
  the `spatie/laravel-webhook-server` dependency that powered 0.1.0 has been removed
  in favor of the in-house engine (Standard Webhooks signing plus the native delivery
  pipeline).
- Migrations were recut for the new storage layer; re-publish and review them before
  upgrading a populated database.
- The dashboard and portal screens follow the design system more closely: the KPI ribbon
  and its loading placeholder share one stats grid, the drawer payload is a copyable code
  block, empty states carry icons, sortable headers are clickable across the whole cell,
  delivery times are localized (relative in the table, absolute on hover), and spacing runs
  on design tokens throughout. The dashboard tab labels are now stored display-ready in the
  translations instead of being cased by CSS.
- Both page shells now expose a `main` landmark and a skip link (WCAG 2.4.1), and every
  action that mutates data is disabled while its request is in flight.
- The publishable stubs are a better reference: deleting an endpoint is confirmed first,
  the zero-row case renders an empty state, and the action column carries an accessible
  name.
- The scaffolding placeholder view `webhooks::example` has been removed.
- The documentation is written for the reader who meets the package for the first time:
  the layer table names the two gate dependencies, the dashboard, the portal and the
  operator console each lead with the packages they need before the first line of
  code, every event the package dispatches is documented in one place with the rule that
  gates it, every publishable tag is listed in one table, the `0.x` upgrade path names the
  adapter that keeps existing consumers verifying and the helper that is gone, and the
  requirements distinguish the layers that need PostgreSQL from a send-only app that
  needs no database at all.

- **The public API says what it is.** Every class that is not part of the advertised
  surface is marked `@internal`, and the README's Versioning section names the surface
  that is not: the manager and facade, the sending builder, the receiving pipeline and
  `ProcessWebhookJob`, the signing contracts and shipped schemes, the SSRF guard, the
  models, enums, exceptions and events, the service providers, the Livewire aliases, the
  published views and migrations, and the config tree. Everything else — the delivery
  pipeline, the response classifier, the config reader, the dashboard's metric objects —
  may change in a minor. A test enforces the boundary, so it cannot erode quietly.
- **No two public classes share a name any more.** The outbound builder is
  `Webhooks\Server\PendingWebhook` (freeing `WebhookCall` to mean the stored inbound
  call, as the `webhook_calls` table always did), the receive-side envelope is
  `Webhooks\Client\InboundMessage` (the signed-bytes unit keeps
  `Core\Signing\WebhookMessage`), and the engine's config reader is
  `Webhooks\Support\Settings`. An app that both sends and receives can now import what
  it needs in one file without aliasing.
- **The transport events are named for what they are.** `Webhooks\Server\Events\*` is
  the per-ATTEMPT family — `WebhookAttemptStarting`, `WebhookAttemptSucceeded`,
  `WebhookAttemptFailed`, `WebhookAttemptRetrying`, `WebhookAttemptDeferred`,
  `WebhookAttemptsExhausted`, plus the once-per-delivery `WebhookDeliveryDispatching` —
  while `Webhooks\Events\*` stays the delivery's final domain outcome. The two families
  no longer share class names, so picking the wrong `WebhookDeliveryFailed` from IDE
  autocompletion (and notifying an endpoint's owner on every retry instead of once) is no
  longer possible. Both families, and the rule that gates them, are documented.
- **The signing namespace reads consistently:** `StripeScheme` and `GitHubScheme`
  (no redundant `Signature` inside `Core\Signing`), and the receive-side event is
  `InvalidWebhookSignature`, matching the other eight events that carry no `Event` suffix.
- **Endpoints have a lifecycle API.** `Webhooks::enable()`, `disable()` and
  `unsubscribe()` join `subscribe()`. `enable()` clears the circuit-breaker streak along
  with the flag — re-activating an endpoint by hand left the streak standing, so the next
  final failure instantly re-disabled the endpoint an owner had just fixed. `is_active` is
  no longer mass-assignable, so the wrong recipe cannot be written by accident, and both
  UI surfaces call the manager instead of hand-rolling the three columns.
- **One convention for every embeddable name.** Livewire aliases and route names are all
  dotted under `webhooks.` — `webhooks.dashboard.page`, `webhooks.self-service.portal`,
  `webhooks.admin.subscriptions`, `webhooks.pulse.deliveries` — replacing the three
  conventions that had grown side by side.
- **The migration publish tags actually work.** `vendor:publish --tag=webhooks-migrations`
  mirrored the per-layer subdirectories into `database/migrations/client/…`, where
  Laravel's migrator (which globs one level) never found them: `php artisan migrate`
  silently skipped the published migration and the first request hit a missing table. Each
  layer now has its own tag — `webhooks-migrations`, `webhooks-client-migrations`,
  `webhooks-server-migrations`, `webhooks-dashboard-migrations` — publishing its files
  flat. Publishing also no longer depends on the Platform layer being enabled.
- **A layer that cannot work refuses to boot.** Switching the dashboard or the portal on
  while `platform.enabled` is false used to end in a raw PostgreSQL `relation
  "webhook_deliveries" does not exist`, from inside a panel query or a materialized-view
  DDL. Both now fail at boot with one sentence naming the two switches involved — the same
  treatment the PostgreSQL driver check already gave.
- The operator console (`webhooks.admin.*`) is documented as what it is: an UNSCOPED
  surface that lists and mutates every tenant's endpoints and deliveries, to be placed
  behind an operator-only gate. The tenant-facing surfaces are the self-service portal and
  the dashboard, both owner-scoped and policy-guarded.

### Removed

- Five configuration keys that nothing read: `core.http.verify`,
  `core.http.response_capture_bytes`, `dashboard.chart.library`, `dashboard.search.driver`
  and `dashboard.scope`, plus `search.driver` (the Scout engine is chosen in
  `config/scout.php`, never here). A published key is public API under Semantic
  Versioning — one that does nothing would have to be kept forever, and it makes every
  key beside it suspect. The dashboard is, and remains, tenant-scoped; its activity chart
  is drawn server-side as SVG and needs no chart library.

### Fixed

- **No transport error can escape the delivery state machine.** Only five cURL error codes
  reach the client as a connection failure; an expired, self-signed or hostname-mismatched
  certificate — and a connection reset or truncated transfer mid-response — arrive as a
  different exception entirely, and used to escape the pipeline, the job and every
  lifecycle event with it: the delivery row stayed `pending` for ever, the circuit breaker
  never counted the failure, and the queue re-released the job with no backoff at all.
  Every way the transport can fail is now a normal retryable outcome that flows through the
  events, the log, the backoff and the breaker. A `failed()` hook and a real backoff
  schedule back it up, so no job death can strand a delivery either.
- **A failed payload offload is no longer silent.** Laravel's filesystem reports a failed
  write by RETURNING FALSE, so a transient disk error used to leave a row pointing at an
  object that does not exist — the received body destroyed, unrecoverable. The write is now
  verified and a failure throws, which lets the producer's (or the queue's) own retry
  deliver the body again.
- **Secret rotation now revokes.** The rotated-away secret was kept for ever — it kept
  signing every delivery and kept verifying — so a rotation revoked nothing. The window is
  now bounded by `platform.secret_rotation_window_hours`, and when it closes the old secret
  is cleared from the row; `webhooks:revoke-rotated-secrets` (scheduled hourly) sweeps the
  endpoints that went quiet before theirs elapsed.
- **A lapsed schedule can no longer stop the partitioning for good.** A delivery that
  landed in the catch-all default partition made PostgreSQL refuse to create the partition
  that should hold it, so `webhooks:partition-maintenance` failed on every later run — and
  never reached the retention prune either. It now drains the default partition first and
  heals itself, reporting the drift it repaired.
- **A NUL byte in a payload no longer destroys the webhook.** PostgreSQL's `jsonb` cannot
  store one at all: inbound it produced a 500 on every retry until the producer gave up,
  outbound it threw mid-fan-out. Payloads are now scrubbed at the edge — once, before they
  are stored and before they are signed — so the stored copy and the delivered bytes stay
  identical.
- **A queued delivery is re-checked against its endpoint before it goes out.** A backlog
  used to keep firing at an endpoint the circuit breaker had just disabled, and — worse —
  at an endpoint its tenant had DELETED. Both are now refused before a byte is sent, and
  recorded on the delivery log with the reason. A replay into a disabled endpoint is
  refused too.
- **A stored inbound call hands back the exact bytes it received.** `body_sha256` promises
  byte fidelity, but the inline path re-encoded the parsed payload — losing the producer's
  whitespace, escaping and float formatting — and a body that did not decode at all (invalid
  UTF-8, a truncated payload, a JSON array) was stored as an empty payload with its bytes
  gone. The received bytes are now kept beside the parsed view, so `hash($call->body())`
  always equals `body_sha256`.
- **A hostile endpoint cannot answer a delivery with a decompression bomb.** The response
  was decoded and fully buffered before the capture cap applied, so a few kilobytes of gzip
  from a tenant-supplied endpoint could inflate to gigabytes inside a worker. Responses are
  no longer decoded, and only the capture prefix is ever kept.
- **A rate-limited event is no longer thrown away.** An over-limit delivery had no row, no
  event and no log line — the operator's first news of it was a customer reporting a webhook
  that never arrived. The limit now SHAPES the endpoint's traffic: the delivery is logged,
  announced (`WebhookDeliveryRateLimited`) and enqueued with a delay.
- **Timestamps are instants, not wall-clock strings.** Every timestamp the package bound
  into SQL was naive, so PostgreSQL resolved it against the database session's time zone.
  Under a non-UTC application timezone every row was written at the wrong instant, every
  metrics window covered the wrong span, and the DST fall-back hour collapsed two distinct
  deliveries onto one `created_at`. Every binding — Eloquent, raw SQL and partition bounds
  alike — now carries its offset.
- **The delivery log's reads and writes prune to one partition.** Locating a row by id
  alone gave the planner nothing to prune with, so every lifecycle event probed the index of
  every partition that had ever existed. The partition key now travels with the delivery.
- The job's timeout derives from the HTTP timeout it wraps, so raising `server.timeout` can
  no longer make the worker kill the job mid-request.
- The signing-secret countdown no longer leaks a timer: its interval is owned by the Alpine
  component and cleared when the reveal card is torn down, so hiding a secret can no longer
  leave a live timer behind.
- The payload-transform editor now names malformed sample JSON instead of silently
  previewing an empty object, and the output preview no longer re-announces the whole
  payload to a screen reader on every keystroke.
- The active dashboard tab now uses design tokens that exist (it silently never received its
  intended weight), and the sortable table headers no longer emit a class that never
  compiled.

### Security

- Tenant isolation now scopes and authorizes by the full `(owner_type, owner_id)` owner
  pair across the self-service portal, the dashboard and the row-level policies, rather
  than by `owner_id` alone. Because an endpoint's owner is a polymorphic relation, two
  tenants that share an owner id under different owner types are distinct tenants; the
  previous id-only checks could let one such tenant view, edit, delete or reveal the
  signing secret of another's endpoints and deliveries. The self-service create path also
  now stores the same owner identity the read scope resolves, so a created endpoint can
  never be owned by a different key than it is filtered by.
- The dashboard metrics, the hourly rollup and the optional search index now scope by the
  same full `(owner_type, owner_id)` owner pair. The KPI, activity, latency, top-events
  and recent-queue panels — and the `webhook_delivery_hourly` materialized view they read,
  which is now grouped and uniquely indexed by the owner pair — previously keyed on
  `owner_id` alone, so a tenant whose id collided with another owner type could see the
  other's delivery rows, event types and aggregate counts. The Scout delivery index now
  carries `owner_type` and `searchForOwner()` filters both columns. The dashboard's default
  tenant resolver also now prefers a current team over the user, matching the self-service
  portal so both resolve the identical tenant.

## [0.1.3] - 2026-07-11

### Fixed

- README PHP-version badge now uses the reliable `packagist/dependency-v` shields endpoint;
  the previous `packagist/php-v` route was rendering "not found".

## [0.1.2] - 2026-07-05

### Added

- Migrating the package against a non-PostgreSQL connection (MySQL or SQLite) now
  fails with one clear, actionable error instead of a cryptic SQL syntax failure —
  it names the offending driver and points to provisioning a Neon (PostgreSQL)
  database on Laravel Cloud. The package remains PostgreSQL-only by design.

### Changed

- Documented the Laravel Cloud database choice in the requirements: use the Neon
  (PostgreSQL) option rather than MySQL.

## [0.1.1] - 2026-07-02

### Changed

- Issue templates (bug report + feature request) now ship to the public repository
  automatically with each release, and a lean `.gitattributes` keeps the Composer
  dist minimal.

## [0.1.0] - 2026-07-02

### Added

- Customer-configurable outgoing webhooks on top of spatie/laravel-webhook-server:
  register endpoints per event type and fan an event out to every matching, active
  subscription with `WebhookEvent::dispatch()` (tenant-scoped or global).
- Postgres delivery log: uuid-keyed, monthly range-partitioned `webhook_deliveries`
  table with a partial index for open rows, plus `webhook_subscriptions` with a jsonb
  event-type list (GIN indexed), an encrypted signing secret, and a nullable owner morph.
- Versioned, Stripe-style HMAC-SHA256 signature (`t=<unix>,v1=<sig>`) signed at send
  time, with zero-downtime secret rotation and a shippable `SignatureVerifier` for consumers.
- SSRF-hardened delivery: every URL is validated at registration and again at send time,
  with the connection pinned to the validated IP to defeat DNS rebinding; private,
  loopback, link-local, unique-local, carrier-grade-NAT, multicast and cloud-metadata
  addresses are refused, and redirects are not followed.
- Stable per-event id for consumer idempotency, preserved across manual redelivery.
- Circuit breaker that auto-disables an endpoint after repeated final failures, and
  `WebhookDeliverySucceeded` / `WebhookDeliveryFailed` / `WebhookEndpointAutoDisabled` events.
- Per-endpoint rate limiting, Horizon tags, a configurable event catalog, and a
  `webhooks:partition-maintenance` command (scheduled daily) for provisioning and retention.
- Optional JSON-Schema payload validation: give an event type a `schema` in the catalog
  and enable `validate_payloads`, and a non-conforming payload is rejected with
  `InvalidPayloadException` before any delivery is created (off by default).
- Optional Livewire management UI shipped as publishable, restyleable stubs
  (`WebhooksUiServiceProvider`, not auto-registered), in two variants: neutral Tailwind
  (`webhooks-ui`) and WireKit-styled (`webhooks-ui-wirekit`).

[Unreleased]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.4...HEAD
[1.4.4]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.3...v1.4.4
[1.4.3]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.2...v1.4.3
[1.4.2]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/pushery/webhooks-for-laravel/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/pushery/webhooks-for-laravel/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/pushery/webhooks-for-laravel/compare/v0.1.3...v1.0.0
[0.1.3]: https://github.com/pushery/webhooks-for-laravel/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/pushery/webhooks-for-laravel/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/pushery/webhooks-for-laravel/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/pushery/webhooks-for-laravel/releases/tag/v0.1.0
