<p align="center">
  <a href="https://github.com/pushery/webhooks-for-laravel">
    <img src="art/header.png" alt="Webhooks for Laravel" width="100%">
  </a>
</p>

# Webhooks for Laravel

[![Latest Version](https://img.shields.io/packagist/v/pushery/webhooks-for-laravel.svg)](https://packagist.org/packages/pushery/webhooks-for-laravel)
[![PHP Version](https://img.shields.io/packagist/dependency-v/pushery/webhooks-for-laravel/php.svg)](https://packagist.org/packages/pushery/webhooks-for-laravel)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-blue.svg)](https://phpstan.org)
[![Code Style](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)
[![License](https://img.shields.io/packagist/l/pushery/webhooks-for-laravel.svg)](LICENSE)

An all-in-one, config-gated webhooks toolkit for Laravel. It **sends** signed
outbound webhooks, **receives** and verifies inbound ones, gives your customers a
**self-service** portal to manage their own endpoints, and puts an **observability**
dashboard over the whole delivery log — and you switch on only the layers you need.
Signatures are [Standard Webhooks](https://www.standardwebhooks.com) by default, so
every delivery is verifiable out of the box by any Standard Webhooks consumer in any
language. The engine is entirely in-house — no third-party webhook-engine
dependency — and PostgreSQL-native.

## The layered architecture

The package is five layers stacked on a shared crypto/transport core. Each has a
single switch, so you pay only for what you turn on. **Configure only what you need.**

| Layer         | What it does                                                                 | Gate                                        |
| ------------- | ---------------------------------------------------------------------------- | ------------------------------------------- |
| **Core**      | Signing dialects, the SSRF guard, and the HTTP transport shared by everything | Always on                                   |
| **Server**    | The outbound delivery engine — sign, queue, retry, back off                  | On by default (`server`)                    |
| **Platform**  | Endpoint subscriptions + event fan-out, the self-service portal, health scoring, payload transforms, egress allowlist, AsyncAPI export | On by default (`platform`); each sub-feature opt-in |
| **Client**    | Inbound receiving — verify, de-duplicate, store and queue incoming webhooks  | Opt-in (`client.enabled`, default `false`)  |
| **Dashboard** | A customer-facing observability UI over the delivery log                      | Opt-in (`dashboard.enabled`) **and** not auto-registered |

Sending and the platform management layer work as soon as the package is installed.
Receiving, the self-service portal, endpoint health scoring, payload transforms, the
dashboard, Laravel Pulse, Scout search, OpenTelemetry, canonical-JSON signing,
Ed25519 signing, the egress proxy, and standalone delivery persistence are each
individually opt-in and off until you enable them.

**Two dependencies between the gates — the switches are not fully independent:**

- **Platform implies Server.** Fan-out delivers *through* the Server engine, so
  `platform.enabled=true` boots the Server layer regardless of `server.enabled`.
  Setting `WEBHOOKS_SERVER_ENABLED=false` on its own therefore changes nothing — to
  stop outbound delivery entirely, set **both** to `false`.
- **Dashboard requires Platform.** The dashboard reads Platform's `webhook_deliveries`
  log, whose migration only runs while `platform.enabled=true`. A dashboard without the
  Platform layer has no table to read (unless you point `dashboard.source_model` at a
  delivery-log model you own).

## Requirements

- PHP 8.4+ with `ext-curl`, `ext-json`, `ext-sodium`
- Laravel 13+
- **PostgreSQL 13+ — for the layers that persist.** The Platform, Client, Dashboard and
  standalone-persistence layers store their tables in PostgreSQL (`jsonb`, GIN indexes,
  partial and partial-unique indexes, declarative range partitioning, a materialized
  view), and their migrations refuse to run on any other driver. A **send-only** app
  (`platform.enabled=false`, no `server.persistence`) runs **no migrations at all** and
  works on any database — see [Send-only setup](#send-only-setup-no-database).
- A queue worker for outbound delivery (Redis recommended so retry backoff never blocks
  other work)
- The UI layers (dashboard, self-service portal, operator console) additionally
  need `livewire/livewire` and `pushery/wirekit` — see [Styling the UI](#styling-the-ui)

> **Deploying to [Laravel Cloud](https://cloud.laravel.com)?** Provision a **Neon
> (PostgreSQL)** database, not the MySQL option. The persistent layers are
> PostgreSQL-only by design; their migrations refuse to run on any other driver with a
> clear error pointing you here.

## Installation

```bash
composer require pushery/webhooks-for-laravel
```

Publish the config and migrations, then migrate:

```bash
php artisan vendor:publish --tag=webhooks-config
php artisan vendor:publish --tag=webhooks-migrations
php artisan migrate
```

`webhooks-migrations` publishes the **Platform** migrations (the subscriptions table and
the delivery log). Each other layer has its own tag — `webhooks-client-migrations`,
`webhooks-server-migrations`, `webhooks-dashboard-migrations` — so you only ever migrate
the layers you switched on; see [Publishable tags](#publishable-tags). Publishing is
optional: with `$runsMigrations` left alone (the default), every enabled layer registers
its own migrations and `php artisan migrate` just runs them.

Every screen this package ships — the [dashboard](#observability-dashboard), the
[self-service portal](#self-service-portal-opt-in) and the
[operator console](#operator-console-opt-in) — is a Livewire component built from
WireKit design-system components. If you plan to use any of them, install both first;
without them a shipped view fails with `Unable to locate a class or view for component
[wirekit::card]`:

```bash
composer require livewire/livewire pushery/wirekit
```

A host on a different UI kit instead publishes the views and restyles them (see
[Publishable tags](#publishable-tags)). Sending and receiving need neither package.

## Quickstart

### Send a signed webhook

```php
use Webhooks\Server\PendingWebhook;

PendingWebhook::create()
    ->url('https://example.com/webhooks')
    ->payload(['invoice_id' => 'in_123', 'amount' => 4200])
    ->useSecret('whsec_your_endpoint_secret')
    ->dispatch();
```

The delivery is queued, signed with a Standard Webhooks signature, and retried with
backoff. Run a queue worker (or use `->dispatchSync()` to send inline).

### Receive and verify one

Enable the Client layer and describe the producer in `config/webhooks.php`:

```php
'client' => [
    'enabled' => true,
    'configs' => [
        [
            'name' => 'partner',
            'secret' => env('PARTNER_WEBHOOK_SECRET'),
            // 'scheme' defaults to Standard Webhooks; set it per source for others.
            'process' => \App\Jobs\HandlePartnerWebhook::class,
        ],
    ],
],
```

Point a route at it with the macro (registered only while the Client layer is on):

```php
use Illuminate\Support\Facades\Route;

Route::webhooks('webhooks/partner', 'partner');
```

An authentic request is verified, de-duplicated, stored and dispatched to your job; a
request whose signature is invalid, expired or malformed is answered `401` and never
reaches your job.

### Send-only setup (no database)

If all you want is the signed, SSRF-guarded, retrying **sender**, switch the Platform
layer off and skip the migrations entirely — no PostgreSQL, no tables, no queries:

```php
// config/webhooks.php
'platform' => ['enabled' => false, /* … */],
'server' => ['persistence' => ['enabled' => false], /* … */],
```

`PendingWebhook` keeps working exactly as above (it needs only a queue), and the package
runs on whatever database your app already uses — or none.

## Sending (Server layer)

`Webhooks\Server\PendingWebhook` is an immutable, fluent builder in the shape of Laravel's
own `PendingRequest`/`PendingMail`: every setter returns a clone, so a half-built call
is a reusable template. `Webhooks\Server\Facades\WebhookSender::to($url)` is a thin,
discoverable entry point to the same builder.

```php
use Webhooks\Server\PendingWebhook;

PendingWebhook::create()
    ->url('https://example.com/webhooks')
    ->payload(['invoice_id' => 'in_123'])   // encoded to JSON and signed
    ->useSecret('whsec_…')
    ->forEventType('invoice.paid')          // recorded and tagged
    ->dispatch();
```

Send a raw, pre-serialized body instead of an array with
`->sendRawBody($body, 'application/json')`.

**Secret rotation.** Sign with the current *and* previous secret during a rotation
window so a consumer that still holds the old secret keeps verifying while it migrates.
For a registered endpoint (`Webhooks::rotateSecret()`) the window is bounded by
`platform.secret_rotation_window_hours` (24 by default) and it CLOSES: once it has, the
old secret is cleared from the row and can no longer sign or verify — which is the whole
point of rotating away from it. `php artisan webhooks:revoke-rotated-secrets` (scheduled
hourly) sweeps the endpoints that went quiet before their window elapsed:

```php
PendingWebhook::create()
    ->url($url)
    ->payload($payload)
    ->useSecrets(current: 'whsec_new', previous: 'whsec_old')
    ->dispatch();
```

**A different signing scheme.** The default is the Standard Webhooks HMAC dialect; opt
into another (for example asymmetric Ed25519) per call:

```php
use Webhooks\Core\Signing\Ed25519Scheme;

PendingWebhook::create()
    ->url($url)
    ->payload($payload)
    ->signUsing(Ed25519Scheme::class)
    ->useSecret('whsk_…')                   // base64 Ed25519 secret key
    ->dispatch();
```

**Retries, backoff and Retry-After.** The backoff is exponential with full jitter,
capped. A retryable `429`/`503` carrying a `Retry-After` header is honored when
scheduling the next attempt, clamped to its own ceiling (`server.backoff.retry_after_cap`
— the longest wait the queue can hold a job for, which is a different quantity from the
jitter cap). When an endpoint asks for longer than that, the delivery comes back at the
cap and the wait is **not** charged against `tries`, so a long rate-limit window cannot
exhaust the delivery before the endpoint is ready for it:

```php
use Webhooks\Server\Backoff\ExponentialWithJitter;

PendingWebhook::create()
    ->url($url)->payload($payload)->useSecret($secret)
    ->maximumTries(5)
    ->useBackoffStrategy(new ExponentialWithJitter(baseSeconds: 10, capSeconds: 900))
    ->respectRetryAfter()                    // on by default
    ->dispatch();
```

**Timeouts, SSRF, mTLS, proxy.** Every outbound URL is vetted by the shared SSRF guard, and a
*direct* connection is pinned to the validated IP (see [Security](#security)). Routing through
`useProxy()` hands name resolution to the proxy, so the pin no longer applies — the proxy must
enforce egress control itself:

```php
PendingWebhook::create()
    ->url($url)->payload($payload)->useSecret($secret)
    ->connectTimeoutInSeconds(3)
    ->timeoutInSeconds(5)
    ->verifySsl(true)                        // or a CA bundle path
    ->useMutualTls(cert: '/path/client.pem', key: '/path/client.key')
    ->useProxy('http://proxy.internal:8080')
    ->dispatch();
```

**Metadata, tags, queue, connection.** Attach arbitrary `meta`, add Horizon tags, and
choose the queue/connection the delivery job runs on:

```php
PendingWebhook::create()
    ->url($url)->payload($payload)->useSecret($secret)
    ->meta(['tenant_id' => $team->id])
    ->withTags(['billing', "team:{$team->id}"])
    ->onQueue('webhooks')
    ->onConnection('redis')
    ->dispatch();
```

Terminal methods: `->dispatch()`, `->dispatchSync()`, `->dispatchIf($cond)`,
`->dispatchUnless($cond)`, and `->toDeliveryData()` (the immutable value object the job
carries).

**Standalone delivery persistence (opt-in).** When you drive `PendingWebhook` directly
*without* the Platform layer and still want a persisted, prunable record of every
delivery, enable `server.persistence.enabled`: a listener upserts each attempt into a
`webhook_server_deliveries` table keyed by the message id, and rows older than
`prune_after_days` are removed by the scheduled `model:prune`. Off by default — when the
Platform layer runs it owns the delivery log instead, so the two never double-log.

## Receiving (Client layer)

Turn on `client.enabled`, declare one entry per producer under `client.configs`, and
route to it with `Route::webhooks($url, $name = null, $verb = 'post')`. The macro binds
a named route (`webhooks.{name}`) and pins the config name onto it. You can also drive
the controller-less processor directly:

```php
use Webhooks\Client\WebhookProcessor;
use Webhooks\Client\WebhookConfig;

$response = new WebhookProcessor($request, WebhookConfig::forName('partner'))->process();
```

The pipeline, in order: capture the exact raw bytes → verify the signature → throttle
the source → de-duplicate → filter → store → dispatch the handler job.

- **Verification.** An invalid, expired or malformed signature responds with
  `invalid_status` (**`401`** by default) — never `500`, because a request that can
  never verify must not tell the sender to retry. Each failure fires a
  `Webhooks\Client\Events\InvalidWebhookSignature`.
- **Replay protection.** The signed timestamp is checked against `tolerance_seconds`
  (default `300`).
- **Idempotency.** Two-tier dedupe on the producer's `webhook-id`: a cache fast path in
  front of a partial-unique insert, so an at-least-once sender (including this package's
  own Server on retry) is never processed twice. A call with no id is always stored.
- **Raw-body capture.** A prepended middleware preserves the exact bytes the signature
  was computed over, before any body parsing.
- **Event routing.** `process` is either a single `ProcessWebhookJob` subclass or an
  `['event.type' => JobClass, '*' => FallbackJob]` map. The job receives the stored call
  and the parsed envelope (`$this->message`).
- **Rate limiting.** An optional per-source token bucket answers an over-limit request
  `429` with `Retry-After`; a forged request never counts, because verification runs
  first.
- **Header redaction.** `store_headers` selects which headers persist; the `redact` list
  (plus `Authorization` and `Cookie`, always) is masked before storage.

Built-in receive adapters, selected per source via `scheme`:

| `scheme`                       | Verifies                                                       |
| ------------------------------ | ------------------------------------------------------------- |
| `StandardWebhooksScheme` (default) | Any Standard Webhooks producer — including this package's own Server |
| `'auto'`                       | States first-party intent explicitly; resolves to Standard Webhooks |
| `StripeStyleScheme`            | The generic `Webhook-Signature: t=,v1=` dialect — **the 0.x format this package used to send**, so a 0.x consumer of yours keeps verifying |
| `StripeScheme`                 | Stripe's `Stripe-Signature: t=,v1=` header                    |
| `GitHubScheme`                 | GitHub's `X-Hub-Signature-256: sha256=` header                |
| `PlainHmacScheme`              | A raw-body HMAC in a `Signature` header                       |
| `Ed25519Scheme`               | The asymmetric `v1a` variant (static key or a JWKS endpoint)  |

`StripeStyleScheme` and `StripeScheme` are **not** interchangeable: the first
reads the generic `Webhook-Signature` header, the second pins Stripe's own
`Stripe-Signature`. Pick by the header the producer actually sends.

Because the default receive scheme is Standard Webhooks, an app **verifies its own
deliveries** with `scheme => 'auto'` and no extra plumbing — a first-party round trip.

## Signatures & interop

The default dialect is **[Standard Webhooks](https://www.standardwebhooks.com)** —
byte-compatible with the specification and its official SDKs, so any Standard Webhooks
consumer can verify our deliveries and we can verify theirs.

- **Signed content:** `{webhook-id}.{webhook-timestamp}.{rawBody}`, HMAC-SHA256,
  base64-encoded.
- **Headers:** `webhook-id`, `webhook-timestamp`, and a `webhook-signature` carrying one
  or more space-separated `v1,<base64>` entries (a rotation emits two — accept if either
  verifies).
- **Key derivation:** strip an optional `whsec_` prefix, then base64-decode the remainder
  to the raw HMAC key bytes.

Other schemes ship for interop: `StripeScheme`, `GitHubScheme` and
`PlainHmacScheme` (receive adapters for those producers), and the asymmetric
**`Ed25519Scheme`** — the Standard Webhooks `v1a` variant, which carries a
`webhook-signature: v1a,<base64>` entry so a receiver only ever holds the public key.
Generate a keypair with `php artisan webhooks:ed25519-keygen` or
`Webhooks\Core\Signing\Ed25519Keys::generate()`. A receiver may pin a static public key
or point `jwks.url` at the producer's JSON Web Key Set of Ed25519 keys — fetched through
the SSRF guard and cached — for rotating provider keys.

**Secret rotation** is first-class: `Webhooks\Core\Signing\SecretSet::rotating($current,
$previous)` (or `->useSecrets()` on a call) signs with both, so verification never breaks
mid-rotation.

**Canonical JSON (opt-in).** Set `server.signing.canonicalize` (or `->canonicalizeJson()`
per call) to sign and send a deterministic, sorted-key body, so a receiver that
re-canonicalizes reproduces the exact signed bytes regardless of key order. Off by
default — the exact bytes you send are already what is signed.

**Published interop vectors.** Known-answer vectors are shipped at
[`resources/interop/standard-webhooks-vectors.json`](resources/interop/standard-webhooks-vectors.json)
(with a [format guide](resources/interop/README.md)) so a third-party receiver — or a
port of the verifier to another language — can prove byte-for-byte compatibility without
trusting this package's code. They cover all three cases an implementer needs: the
canonical symmetric `v1` example from the specification, an asymmetric **`v1a` Ed25519**
vector (public key, message, expected signature — Ed25519 signing is deterministic, so a
correct port reproduces it exactly), and **negative** vectors that must *fail* to verify,
because an implementation that accepts everything also passes every positive test. Tests
in the suite re-verify each shipped vector against the engine, so the published contract
can never drift.

## Platform (subscriptions, fan-out, self-service, health, transforms)

The Platform layer turns the delivery engine into a product. It is on by default; each
capability below is individually gated.

**Subscriptions & fan-out.** Register endpoints per event type and fan an event out to
every matching, active subscription:

```php
use Webhooks\Facades\Webhooks;
use Webhooks\WebhookEvent;

$subscription = Webhooks::subscribe(
    owner: $team,                            // any Eloquent model, or null for a global endpoint
    url: 'https://example.com/webhooks',
    eventTypes: ['invoice.paid'],
);
$subscription->secret;                       // reveal once — it signs their deliveries

WebhookEvent::dispatch('invoice.paid', ['invoice_id' => 'in_123'], tenant: $team);
```

Each subscriber receives a signed POST whose JSON body is an envelope:

```json
{
  "id": "0192…-uuid",
  "type": "invoice.paid",
  "created_at": "2026-07-01T12:00:00+00:00",
  "data": { "invoice_id": "in_123" }
}
```

The `id` is the Standard Webhooks `webhook-id` and is stable across redeliveries, so
consumers can deduplicate on it. Supporting operations: `Webhooks::ping($subscription)`
(a one-off test event), `Webhooks::rotateSecret($subscription)`, and
`Webhooks::redeliver($delivery)` (a replay that keeps the original event id).

An optional **event catalog** (`platform.catalog`) documents each type and can carry a
JSON Schema; enable `platform.validate_payloads` and a non-conforming payload is rejected
with `Webhooks\Exceptions\InvalidPayloadException` before any delivery is created.

### Self-service portal (opt-in)

A tenant-scoped surface where a customer manages its *own* endpoints — a paginated list
with a health badge, a create/edit form that SSRF-vets the URL, a secret panel that
reveals and rotates the signing secret, an endpoint health matrix, and a
payload-transform editor. These are **real, full-page screens that ship with the
package**, not a headless seam you have to build against.

Three steps, all required:

```bash
# 1. The portal's screens are Livewire components built from WireKit.
composer require livewire/livewire pushery/wirekit
```

```php
// 2. Register the provider — it is NOT auto-registered.
// bootstrap/providers.php
Webhooks\Platform\SelfServicePortalServiceProvider::class,
```

```php
// 3. config/webhooks.php — switch the layer on.
'platform' => ['self_service' => ['enabled' => true, /* … */]],
```

Every query is scoped so a tenant only ever sees the endpoints it owns, guarded by the
`manage-webhook-endpoints` gate and a row-level `WebhookSubscriptionPolicy`. It mounts at
`route_prefix` behind `middleware`, `max_endpoints_per_tenant` caps registrations, and the
views are publishable (`--tag=webhooks-self-service-views`) if you are on another UI kit
and want to restyle them.

**Endpoint health scoring (opt-in).** Each active endpoint earns a 0–100 score blended
from its recent success rate, a p95-latency penalty and a consecutive-failure penalty,
mapped onto a `healthy` / `degraded` / `failing` band (`unknown` with no history). The
score comes from a single aggregate query:

```php
use Webhooks\Platform\Health\EndpointHealth;

$report = app(EndpointHealth::class)->scoreFor($subscription);

$report->score;       // 0–100, or null with no history to score
$report->status;      // HealthStatus::Healthy / Degraded / Failing / Unknown
$report->successRate; // and the raw signals the score was blended from
$report->p95;
$report->sampleSize;
```

`php artisan webhooks:refresh-endpoint-health` caches the score onto the subscription.
With `platform.health.enabled`, a finished delivery also refreshes its own endpoint's
cached score, and the command is scheduled to sweep every active endpoint.

**Payload transforms & versioning (opt-in).** With `platform.payload_versioning.enabled`,
an endpoint may carry a `payload_version` and/or a stored declarative `transform`, and the
event data is reshaped for that endpoint *before* the body is signed — so the transformed
bytes are the signed-and-sent bytes. The `DeclarativePayloadTransformer` is safe and
data-driven (no callables): `include` / `exclude` field lists, `rename`, `rewrap`, and a
stamped `payload_version`. Two endpoints on the same event with different versions
therefore receive different bodies.

**Egress allowlist.** `php artisan webhooks:egress-ips` prints the configured
`core.egress.published_ips` (json/txt/md) for a consumer to allowlist on its firewall, and
an optional `core.egress.proxy` routes every outbound delivery through a forward proxy.

> **The IP pin does not survive a proxy.** The SSRF guard vets and pins a destination IP for
> *direct* connections. A forward proxy resolves the hostname itself, so the pin is **not**
> enforced through it and the anti-rebinding guarantee becomes the proxy's responsibility —
> your proxy must enforce its own egress control. Leave `core.egress.proxy` unset unless the
> proxy does that.

**AsyncAPI export.** `php artisan webhooks:asyncapi` builds an AsyncAPI 3.0 document from
the event catalog — one channel, operation and message per event type, each carrying the
type's schema, example and description (JSON by default, YAML when `symfony/yaml` is
installed).

## Observability (Dashboard)

An opt-in, customer-facing analytics UI over the delivery log. It reads; it records
nothing. Three steps, all required:

```bash
# 1. The panels are Livewire components built from WireKit.
composer require livewire/livewire pushery/wirekit
```

```php
// 2. Register the provider — it is NOT auto-registered.
// bootstrap/providers.php
Webhooks\Dashboard\WebhooksDashboardServiceProvider::class,
```

```php
// 3. config/webhooks.php — switch it on. It reads Platform's delivery log,
//    so the Platform layer must stay enabled (or point 'source_model' at your own).
'dashboard' => ['enabled' => true, /* … */],
```

The class-based Livewire 4 panels show KPI cards, a stacked hourly-activity chart (drawn
server-side as SVG — no chart library, no compiled JS), latency percentiles
(P50/P90/P95/P99), a live recent-delivery queue, top event types, an endpoint setup
summary, and a sortable, filterable, paginated deliveries table with a detail drawer and
one-click redelivery — on a tabbed full-page component. Access is guarded by a
`view-webhook-dashboard` gate and a `WebhookDeliveryPolicy`, and every query is
tenant-scoped.

The read model is an hourly materialized view refreshed by
`php artisan webhooks:refresh-metrics` (scheduled at `dashboard.metrics.refresh`). The
page mounts at `dashboard.prefix` behind `dashboard.middleware`; the Blade views are
publishable (`--tag=webhooks-dashboard-views`) for hosts on another UI kit — publishing
and restyling them is the supported escape hatch from WireKit. For very high volume,
`dashboard.percentiles.driver = 'tdigest'` reads percentiles from per-bucket digests
(requires the PostgreSQL `tdigest` extension).

### JSON metrics endpoint (opt-in)

Set `dashboard.expose_json_api` to `true` and the same read model is served as JSON, so you
can drive your own charts, a status page or an alerting rule from the dashboard's numbers.
While the flag is `false` (the default) the route is not registered at all.

```
GET /webhooks/api/metrics?window=24h        # route name: webhooks.dashboard.metrics
```

It mounts at `dashboard.api_path` (default `api/metrics`) under `dashboard.prefix`, behind
the same `dashboard.middleware` and the same `view-webhook-dashboard` gate as the page, and
every read is scoped to the acting tenant — nobody ever reads another tenant's numbers.

| Query parameter | Values                                     | Default                |
| --------------- | ------------------------------------------ | ---------------------- |
| `window`        | any token from `dashboard.windows`         | the first one (`24h`)  |

A window the host does not offer is rejected with `422` — it never falls back silently. The
response carries **aggregates only**: no delivery rows, payloads, headers or signing
material are exposed. Latencies are milliseconds; `retry_rate` is a percentage.

```json
{
  "window": "24h",
  "generated_at": "2026-01-31T09:15:00+00:00",
  "kpis": {
    "total": 5,
    "delivered": 3,
    "pending": 1,
    "failed": 1,
    "retried": 1,
    "retry_rate": 20.0,
    "p50_ms": 20.0,
    "p90_ms": 20.0,
    "p95_ms": 20.0,
    "p99_ms": 20.0
  },
  "hourly": [
    {
      "bucket": "2026-01-31T09:00:00+00:00",
      "total": 5,
      "delivered": 3,
      "pending": 1,
      "failed": 1,
      "retried": 1,
      "p50_ms": 20.0,
      "p95_ms": 20.0
    }
  ],
  "top_events": [{ "event_type": "invoice.paid", "total": 5 }]
}
```

A separate, single-view **Laravel Pulse** card for your own engineers is available under
`pulse.enabled` (its provider is not auto-registered and `laravel/pulse` stays a
suggestion) — throughput, failure rate and latency of outbound deliveries by event type.

## Events

Events are the package's main extension point: this is where you notify an endpoint's
owner, page on-call, write an audit trail or broadcast a live dashboard. They come in two
families, and **which one you want depends on one question: do you run the Platform
layer?**

**The transport family — `Webhooks\Server\Events\*`.** Dispatched by the delivery engine
itself, so they fire for **every** delivery, whether it came from `Webhooks::dispatch()`,
a `PendingWebhook` you built by hand, or a redelivery. They are scoped to a single **HTTP
attempt** and carry the transport's value object (`WebhookDeliveryData`), not a model.

| Event | Fires | Carries |
| ----- | ----- | ------- |
| `WebhookDeliveryDispatching` | once per delivery, synchronously, before it is queued | `data` |
| `WebhookAttemptStarting` | before each HTTP request | `data`, `attempt` |
| `WebhookAttemptSucceeded` | an attempt returned 2xx (the delivery is done) | `data`, `attempt`, `response` |
| `WebhookAttemptFailed` | **each** failed attempt — fires again on every retry | `data`, `attempt`, `response?`, `exception?` |
| `WebhookAttemptRetrying` | a retry has been scheduled | `data`, `attempt`, `delaySeconds` |
| `WebhookAttemptDeferred` | a `Retry-After` beyond the cap was waited out, off the retry budget | `data`, `attempt`, `delaySeconds`, `requestedSeconds` |
| `WebhookAttemptsExhausted` | **once**, when the delivery gives up for good | `data`, `attempt`, `response?`, `exception?` |

**The domain family — `Webhooks\Events\*`.** Dispatched by the Platform layer as it
writes the delivery log, so they fire **only while `platform.enabled` is true**. They are
scoped to the **delivery**, not the attempt, and carry the Eloquent models.

| Event | Fires | Carries |
| ----- | ----- | ------- |
| `WebhookDeliverySucceeded` | the delivery was accepted (2xx) | `delivery` |
| `WebhookDeliveryFailed` | the delivery exhausted its retries — **once** | `delivery`, `reason` |
| `WebhookDeliveryRateLimited` | an over-limit delivery was deferred rather than dropped | `delivery`, `delaySeconds` |
| `WebhookEndpointAutoDisabled` | the circuit breaker disabled an endpoint | `subscription` |

Plus one on each end of the package: `Webhooks\Client\Events\InvalidWebhookSignature`
(an inbound request failed verification; carries the request, the source config and a
coarse reason) and `Webhooks\Dashboard\Events\WebhookRedeliveryRequested` (an operator
asked the dashboard to replay a delivery).

Two rules worth pinning up:

- **"Attempt" is not "delivery".** `WebhookAttemptFailed` fires on every failed try, so a
  notification wired to it goes out once per retry. The delivery gives up exactly once,
  and says so as `WebhookAttemptsExhausted` (transport) / `WebhookDeliveryFailed`
  (domain). Notify from those.
- **Send-only apps get the transport family only.** With `platform.enabled=false` there
  is no delivery log and no `Webhooks\Events\*` — a listener on them would never fire and
  nothing would tell you. Listen to `Webhooks\Server\Events\*` instead; they are always
  dispatched.

```php
// Platform app: notify the endpoint's owner once, when the delivery is dead.
Event::listen(function (Webhooks\Events\WebhookDeliveryFailed $event): void {
    $event->delivery->subscription->owner?->notify(new WebhookEndpointFailing($event->reason));
});

// Send-only app: the same moment, from the engine itself.
Event::listen(function (Webhooks\Server\Events\WebhookAttemptsExhausted $event): void {
    Log::error('Webhook gave up', ['url' => $event->data->url, 'attempts' => $event->attempt]);
});
```

## Operator console (opt-in)

Two embeddable Livewire components for the screens **you** run — an operator managing
every endpoint and browsing the delivery log — as distinct from the customer-facing
self-service portal and dashboard above. They render inside *your* layout, on *your*
authorized pages, rather than mounting routes of their own.

> **These two are unscoped, by design.** They list and mutate **every** tenant's
> endpoints and deliveries, and the endpoints they register are global (owner-less), so
> every tenant's events reach them. That is what an operator console is — and it means
> you must place them behind an operator-only gate. For anything a **customer** touches,
> use the tenant-scoped, policy-guarded surfaces instead: the
> [self-service portal](#self-service-portal-opt-in) for managing endpoints, the
> [dashboard](#observability-dashboard) for the delivery log.

```bash
composer require livewire/livewire
```

```php
// bootstrap/providers.php — not auto-registered.
Webhooks\WebhooksUiServiceProvider::class,
```

```blade
{{-- your own page, behind your own authorization --}}
<livewire:webhooks.admin.subscriptions />
<livewire:webhooks.admin.deliveries />
```

The two components ship in two view variants and you publish **exactly one** — both land at
`resources/views/vendor/webhooks/livewire` and the second would overwrite the first:

```bash
php artisan vendor:publish --tag=webhooks-ui           # neutral Tailwind stubs
php artisan vendor:publish --tag=webhooks-ui-wirekit   # WireKit-styled stubs
```

Publishing is optional — the package's own views render as-is (the neutral variant needs
no design system; the WireKit variant needs `pushery/wirekit`). Publish when you want to
restyle them, and treat the stubs as a starting point you own.

### Publishable tags

| Tag | Publishes |
| --- | --- |
| `webhooks-config` | `config/webhooks.php` |
| `webhooks-migrations` | The Platform migrations — subscriptions + the delivery log |
| `webhooks-client-migrations` | The Client migration — `webhook_calls` |
| `webhooks-server-migrations` | The standalone-persistence migration — `webhook_server_deliveries` |
| `webhooks-dashboard-migrations` | The dashboard's hourly materialized view |
| `webhooks-lang` | The translation files (seven locales), to override a string or add another |
| `webhooks-views` | The shared views (including the `webhooks::pagination` control) |
| `webhooks-dashboard-views` | The observability dashboard's views |
| `webhooks-self-service-views` | The self-service portal's views |
| `webhooks-ui` | The operator console, neutral Tailwind variant |
| `webhooks-ui-wirekit` | The operator console, WireKit variant |

There is **one migration tag per layer**, and each publishes its files flat into
`database/migrations` — where the migrator actually looks. Publish only the layers you
switched on: a published migration *runs*, so publishing all of them would create tables
for layers you never enabled.

## Styling the UI

**Required for every screen this package renders** — the dashboard, the self-service
portal and both publishable stubs.

The package ships **no compiled stylesheet**. Its views are Tailwind utilities over
WireKit's design tokens: `@wirekitStyles` brings the tokens, and **your** Tailwind build
compiles the utilities that consume them. That build has to be told where to look — for
WireKit's components *and* for this package's views. Both source registrations are
required; with either one missing the screens render unstyled.

```css
/* resources/css/app.css */
@import 'tailwindcss';

/* This package's views. */
@import '../../vendor/pushery/webhooks-for-laravel/resources/css/webhooks.css';

/* WireKit's components (required by every WireKit consumer — see its install notes). */
@source '../../vendor/pushery/wirekit/resources/views/**/*.blade.php';
```

Install the icon set the screens draw their empty states and primary actions against.
Without it WireKit renders an inert placeholder where each icon would be — the pages
still work, they simply lose their iconography:

```bash
composer require blade-ui-kit/blade-icons blade-ui-kit/blade-heroicons
```

**Dark mode.** WireKit's dark tokens live behind a `.dark` class on the document root.
Because the dashboard and the portal are the *package's* layouts, the package puts it
there: `ui.theme` is `auto` by default, which mirrors the reader's system preference (and
keeps mirroring it if they change it). Pin it with `WEBHOOKS_UI_THEME=light` or `=dark` —
which is also how you switch off the small inline head script under a strict
Content-Security-Policy. Two package-level custom properties retune the plot heights on
the dashboard without forking a view: `--wh-chart-height` and `--wh-sparkline-height`.

If you are on another UI kit entirely, publish the views (`--tag=webhooks-views`,
`--tag=webhooks-dashboard-views`, `--tag=webhooks-self-service-views`) and restyle them;
the pagination control (`webhooks::pagination`) publishes with them.

## Configuration

Every option is documented inline in `config/webhooks.php`. The section tree:

| Section     | Gate (default)                              | Contents                                                                                     |
| ----------- | ------------------------------------------- | -------------------------------------------------------------------------------------------- |
| `core`      | always on                                   | `signing.scheme`, the `ssrf` policy, the `egress` allowlist + proxy                          |
| `server`    | `server` (on) — **forced on by `platform`** | queue/connection, `signing` (canonicalize, ed25519), `http_verb`, timeouts, `tries`, `backoff`, `no_retry_on_4xx`, `persistence`, `large_payload`, `verify_ssl`, `horizon_tags` |
| `platform`  | `platform` (on)                             | `catalog`, `validate_payloads`, `circuit_breaker`, `rate_limit`, retention/partitioning, `self_service`, `health`, `payload_versioning` |
| `client`    | `client.enabled` (**off**)                  | `raw_body_capture`, per-source `configs`, `delete_after_days`                                 |
| `dashboard` | `dashboard.enabled` (**off**) — needs `platform` | route `prefix`/`middleware`, `source_model`, `windows`, `poll_interval`, `percentiles`, `metrics.refresh`, `expose_json_api` + `api_path` |
| `pulse`     | `pulse.enabled` (**off**)                   | the internal-ops Pulse card                                                                   |
| `search`    | `search.enabled` (**off**)                  | optional Laravel Scout full-text index over the delivery/call logs                           |
| `otel`      | `otel.enabled` (**off**)                    | a dependency-free OpenTelemetry span seam per finished delivery                               |
| `ui`        | always on                                   | `theme` (`auto` / `light` / `dark`) for the package's own full-page layouts                    |

Sub-feature switches default off: `server.persistence.enabled`,
`server.signing.canonicalize`, `server.signing.ed25519.enabled`, `core.egress.enabled`,
`platform.self_service.enabled`, `platform.health.enabled`,
`platform.payload_versioning.enabled`.

Every `server` key is the **default for each outbound call**; the `PendingWebhook` builder
overrides any of them per delivery. Turning on `server.signing.ed25519.enabled` switches
*every* delivery to the asymmetric `v1a` signature made with
`server.signing.ed25519.secret_key` — the per-endpoint shared secret then plays no part,
and each receiver verifies with the public key alone.

## Localization

Every string the shipped UI puts in front of a user — headings, labels, placeholders,
buttons, empty states, toasts, validation messages, status badges and the accessible names
a screen reader announces — is translated, never hardcoded. The package ships **seven
languages** — English, German, Spanish, French, Italian, Dutch and Portuguese — and
translations resolve under the `webhooks` namespace, one file per surface:

| File | Surface |
| --- | --- |
| `dashboard` | the observability dashboard |
| `self-service` | the endpoint portal |
| `management` | the [operator console](#operator-console-opt-in) (both stub variants) |
| `pulse` | the Laravel Pulse card |

```php
__('webhooks::dashboard.table.replay');
__('webhooks::self-service.form.register');
__('webhooks::management.form.submit');
```

The rendered locale is simply the host app's (`app()->getLocale()`), so nothing needs
configuring — set the locale as you already do and the UI follows. Status and health values
are translated for display only; what is persisted (`succeeded`, `failed`, `degraded`, …)
never changes. The endpoint form carries its own validation messages, so a refused save
reads correctly even on a host that never translated Laravel's own validation lines.

### Overriding a string

Publish the translations and edit them in your app:

```bash
php artisan vendor:publish --tag=webhooks-lang
```

They land in `lang/vendor/webhooks/{locale}/`, where the host's file wins over the
package's for every key it defines — copy only the keys you want to change.

### Adding a locale

Publish as above, copy `lang/vendor/webhooks/en` to your locale's directory
(`lang/vendor/webhooks/fr`, say) and translate the values, leaving the keys untouched. Any
key you leave out falls back to the package's English, so a partial translation still
renders — and you can fill the rest in later.

## Security

Webhook URLs are attacker-influenced, so every endpoint is validated when it is
registered **and** again immediately before each delivery, with the connection pinned to
the validated IP so a rebinding DNS record cannot redirect it. Private, loopback,
link-local, unique-local, carrier-grade-NAT, multicast and cloud-metadata
(`169.254.169.254`) addresses are refused, redirects are not followed, and TLS
verification stays on. Signing secrets are stored encrypted at rest; inbound sensitive
headers (`Authorization`, `Cookie`, plus your `redact` list) are masked before storage.

> ⚠️ **`core.ssrf.allowed_hosts` is an opt-*out*, not an allowlist.** A host you put on
> it skips DNS resolution, skips the private/loopback/metadata IP classification **and
> skips the IP pin** — so that host may resolve into your internal network and its DNS
> record may rebind between the check and the connection. Use it only for a known
> internal endpoint whose risk you accept. The *restrictive* list is
> `core.ssrf.blocked_hosts`. And `core.ssrf.block_private_networks = false` switches the
> guard off globally — leave it `true`.

Report vulnerabilities per the [security policy](SECURITY.md), privately.

## Reliability

- **Retries & backoff** — exponential with full jitter, capped, Retry-After-aware
  (`server.tries`, `server.backoff`). `no_retry_on_4xx` keeps a permanent `400`/`410`
  from being retried for hours while `408`/`425`/`429` still are.
- **Idempotency** — a stable per-event id (the Standard Webhooks `webhook-id`), preserved
  across redelivery, and two-tier inbound dedupe on receipt.
- **Circuit breaker** — after `platform.circuit_breaker.threshold` consecutive final
  failures an endpoint auto-disables and a `Webhooks\Events\WebhookEndpointAutoDisabled`
  event fires; a single success resets it.
- **Rate limiting** — a per-subscription outbound cap (`platform.rate_limit`) and an
  optional per-source inbound cap keep one slow endpoint from starving the queue. The
  outbound cap SHAPES traffic rather than dropping it: an over-limit delivery is logged,
  announced (`Webhooks\Events\WebhookDeliveryRateLimited`) and enqueued with a delay, so a
  burst is spread across the following minutes instead of being lost.
- **Partitioning & retention** — the delivery log is monthly range-partitioned;
  `php artisan webhooks:partition-maintenance` (scheduled daily) provisions upcoming
  partitions, drops those past `platform.retention_months` — a metadata operation, not a
  bulk `DELETE` — and drains any delivery that landed in the catch-all default partition
  while the schedule was behind, so a lapse in the cron cannot permanently stop either.
- **Lifecycle events** — every delivery announces its fate. Which family to listen to
  depends on whether you run the Platform layer; see [Events](#events).

## Upgrading from 0.x

Version 1.0.0 is a ground-up rewrite; treat it as a new major.

- **No third-party webhook-engine dependency.** The delivery engine is now entirely
  in-house. Sending moved to the fluent `Webhooks\Server\PendingWebhook` builder.
- **Standard Webhooks is now the default signature.** Deliveries carry
  `webhook-id` / `webhook-timestamp` / `webhook-signature: v1,<base64>` over
  `{id}.{timestamp}.{rawBody}` — replacing 0.x's single
  `Webhook-Signature: t=<unix>,v1=<hex>` header. Any Standard Webhooks SDK verifies them.
  **Your existing consumers keep working:** that 0.x dialect ships on as the
  `Webhooks\Core\Signing\StripeStyleScheme` **receive** adapter, so an app that receives
  0.x-format traffic verifies it by setting `scheme => StripeStyleScheme::class` on the
  source. (Do **not** reach for `StripeScheme` — it pins Stripe's own
  `Stripe-Signature` header and will not verify a `Webhook-Signature` one.)
- **`Webhooks\Signing\SignatureVerifier` is removed.** The 0.x helper you shipped to
  consumers is gone: a consumer now verifies with any Standard Webhooks SDK (in any
  language), or, while it is still on the old format, with `StripeStyleScheme` during the
  migration window.
- **Config was reorganized** under `core` / `server` / `platform` / `client` /
  `dashboard` (plus `pulse` / `search` / `otel`). Re-publish `config/webhooks.php` and
  move your settings into the new tree; the old flat keys are gone.
- **New capabilities:** inbound receiving, a self-service portal, endpoint health scoring,
  per-endpoint payload transforms, and an observability dashboard — all opt-in.
- **Migrations were recut.** Re-publish and review the migrations before upgrading a
  populated database.

Because this is a new major, review your integration end to end rather than expecting a
drop-in bump.

## Testing your integration

Every delivery is a queued job, so `Bus::fake()` asserts the fan-out without a network
call:

```php
use Illuminate\Support\Facades\Bus;
use Webhooks\Server\Jobs\CallWebhookJob;
use Webhooks\WebhookEvent;

Bus::fake();

WebhookEvent::dispatch('invoice.paid', ['invoice_id' => 'in_123'], tenant: $team);

Bus::assertDispatched(CallWebhookJob::class);
```

Use `->dispatchSync()` on a `PendingWebhook` when you want the request to actually go out
(against a fake HTTP server, say), and drive the inbound side by posting a request signed
with `Webhooks\Core\Signing\StandardWebhooksScheme` — the same class the sender uses, so
a first-party round trip is testable end to end.

Working on the package itself? Its own suite runs against a real **PostgreSQL 13+**
database (there is no SQLite fallback): `createdb webhooks_for_laravel_test`, then point
`DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` at it if it is not
`postgres@127.0.0.1:5432`.

## Versioning

This package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html), and it
tells you exactly which classes that promise covers.

**The public API is:**

- `WebhookManager` and the `Webhooks` facade, and `WebhookEvent`
- Sending: `Server\PendingWebhook` and `Server\Facades\WebhookSender`, the backoff
  contract, the queued `CallWebhookJob`, and the delivery value objects the events carry
- Receiving: `Client\WebhookProcessor`, `Client\WebhookConfig`, `Client\InboundMessage`,
  `Client\Jobs\ProcessWebhookJob` and the profile / response contracts
- Signing: the `Core\Signing\SignatureScheme` contract, every shipped scheme, and
  `SecretSet` / `WebhookMessage` / `SignatureHeaders` / `VerificationResult`
- The SSRF guard contract, the models, the enums, the exceptions, and **all the
  [events](#events)**
- The service providers, the Livewire component aliases, the published views and
  migrations, the config tree, and the publish tags

**Everything else is `@internal`** — the delivery pipeline, the response classifier, the
config reader, the SSRF resolver internals, the dashboard's metric objects, the
listeners — and **may change in a minor release**. Those classes carry an `@internal`
docblock tag, your IDE and static analyser will say so, and a test in this repository
fails if a class outside the list above ever loses the tag. Bind to the list; leave the
rest to the engine.

## Built by Pushery

This package is built and maintained by [Pushery](https://www.pushery.com) — a
Berlin-based studio building Laravel applications, SaaS products, and open-source
tools.

Building a Laravel UI? [WireKit](https://wirekit.app), Pushery's open-source
Livewire component kit, gives you a polished component library out of the box.
Browse the rest of our work at [pushery.com](https://www.pushery.com).

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
