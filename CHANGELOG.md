# Changelog

All notable changes to `pushery/webhooks-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[Unreleased]: https://github.com/pushery/webhooks-for-laravel/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/pushery/webhooks-for-laravel/compare/v0.1.3...v1.0.0
[0.1.3]: https://github.com/pushery/webhooks-for-laravel/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/pushery/webhooks-for-laravel/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/pushery/webhooks-for-laravel/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/pushery/webhooks-for-laravel/releases/tag/v0.1.0
