---
name: webhooks-for-laravel
description: >
  Install, configure, and apply the Webhooks for Laravel package in a Laravel
  application — send signed outbound webhooks, receive and verify inbound ones,
  and switch on only the layers the application needs.
license: MIT
metadata:
  author: pushery
---

# Webhooks for Laravel

Use this skill when a Laravel application installs or integrates the
`pushery/webhooks-for-laravel` package. Laravel Boost surfaces it inside
consuming applications, so keep it focused on adoption — never on package
internals.

## Primary Goal

Apply the package's public API in the smallest correct way for the consuming
application. This package is **config-gated**: almost everything is off until it
is switched on, so the smallest correct integration is usually much smaller than
the feature list suggests.

## Workflow

### 1. Install

```bash
composer require pushery/webhooks-for-laravel
```

Four of the eight service providers are registered automatically through package
discovery. **Four are not, and nothing tells you** — the opt-in ones, which are
also the ones that put something on a page:

```php
// bootstrap/providers.php — add the ones whose layer you switch on
Pushery\Webhooks\Platform\SelfServicePortalServiceProvider::class,   // self-service portal
Pushery\Webhooks\Dashboard\WebhooksDashboardServiceProvider::class,  // observability dashboard
Pushery\Webhooks\WebhooksUiServiceProvider::class,                    // operator console
Pushery\Webhooks\Pulse\WebhookPulseServiceProvider::class,           // Laravel Pulse card
```

Without the matching line, the layer's config switch reads as on and the page
fails at render with `Unable to find component: [webhooks.…]` — a message that
sends you looking at Livewire rather than at a missing provider.

### 2. Decide which layers the application needs

Five layers sit on a shared crypto/transport core, each with one switch:

| Layer | What it does | Default |
| --- | --- | --- |
| Core | Signing dialects, SSRF guard, HTTP transport | Always on |
| Server | Outbound delivery — sign, queue, retry, back off | On |
| Platform | Endpoint subscriptions, event fan-out, self-service portal | On · portal needs a manual provider |
| Client | Inbound receiving — verify, de-duplicate, store, queue | Off |
| Dashboard | Customer-facing observability UI over the delivery log | Off · needs a manual provider |

Two dependencies between the gates bite silently:

- **Platform implies Server.** Fan-out delivers *through* the Server engine, so
  `platform.enabled=true` boots Server regardless of `server.enabled`. To stop
  outbound delivery entirely, set **both** to `false`.
- **Dashboard requires Platform.** The dashboard reads Platform's
  `webhook_deliveries` log, whose migration only runs while `platform.enabled=true`.
- **Anything with a screen requires two more packages.** The dashboard and the
  self-service portal are Livewire components whose views are built from WireKit
  components, and neither is a hard dependency of this package — turning the layer on
  without them fails at render with `Unable to locate a class or view for component
  [wirekit::card]`, not at boot:

  ```bash
  composer require livewire/livewire pushery/wirekit
  ```

  A host on a different UI kit publishes the views instead
  (`--tag=webhooks-dashboard-views` / `--tag=webhooks-self-service-views`) and restyles
  them. Either way the host's own Tailwind build has to scan both packages' views.

### 3. Configure

```bash
php artisan vendor:publish --tag=webhooks-config
```

Every option in `config/webhooks.php` is documented inline. Publishing is
optional — the package merges its own defaults, so publish only when overriding
something.

**Keep the published file down to what you changed.** It is merged over the
shipped defaults all the way down, so deleting the rest is safe and is how you
keep receiving options later versions add. A map is merged key by key; a **list**
is replaced whole and never appended to (`platform.catalog`,
`dashboard.windows`, `dashboard.middleware`, `server.retryable_4xx`,
`core.ssrf.allowed_hosts` and the others are lists). To switch something off,
write `=> null` — removing the key means "no opinion" and gives you the default
back.

Migrations are published **one tag per layer**, never all at once: a published
migration *runs*, so publishing every tag would create tables for layers the
application never enabled.

| Tag | Publishes |
| --- | --- |
| `webhooks-migrations` | Platform: subscriptions + the delivery log |
| `webhooks-client-migrations` | Client: `webhook_calls` |
| `webhooks-server-migrations` | Standalone persistence: `webhook_server_deliveries` |
| `webhooks-dashboard-migrations` | The dashboard's hourly materialized view |

Publishing migrations is itself optional: with `$runsMigrations` left alone,
every enabled layer registers its own and `php artisan migrate` runs them.

The persistent layers need **PostgreSQL or MySQL 8.4+**. An application that only
sends needs no database at all — see the send-only path below.

### 4. Apply the package

**Send a signed webhook.** The delivery is queued, signed with a Standard
Webhooks signature, and retried with backoff:

```php
use Pushery\Webhooks\Server\PendingWebhook;

PendingWebhook::create()
    ->url('https://example.com/webhooks')
    ->payload(['invoice_id' => 'in_123', 'amount' => 4200])
    ->useSecret('whsec_your_endpoint_secret')
    ->dispatch();
```

`dispatch()` returns the queued `WebhookDeliveryData`. Its `messageId` is stable
across retries and is the correlation key to record against the application's own
log. Use `->dispatchSync()` to send inline instead of queueing.

**Fan an event out, or aim it at one endpoint.** With the Platform layer on,
`Webhooks::dispatch($type, $payload, $tenant)` delivers to every active endpoint
subscribed to that type. Its third argument narrows to a **tenant**, not to an
endpoint — two endpoints of the same customer share one — so when the application
routes *rule to endpoint* rather than *event to every endpoint of that type*, use:

```php
use Pushery\Webhooks\Facades\Webhooks;

$delivery = Webhooks::dispatchTo($subscription, 'invoice.paid', ['invoice_id' => 'in_123']);
```

One subscription in, one `WebhookDelivery` out, no other endpoint touched. It is
the same delivery chain as a fan-out. Distinct from `ping()` (a fixed test body)
and `redeliver()` (a replay of an existing delivery). An inactive endpoint, or one
not subscribed to that type, raises `SubscriptionNotListening` rather than
receiving it or being skipped in silence.

**Receive and verify one.** Switch the Client layer on and describe the producer:

```php
// config/webhooks.php
'client' => [
    'enabled' => true,
    'configs' => [
        [
            'name' => 'partner',
            'secret' => env('PARTNER_WEBHOOK_SECRET'),
            // Must be a ProcessWebhookJob subclass — anything else throws when
            // the config resolves.
            'process' => \App\Jobs\HandlePartnerWebhook::class,
        ],
    ],
],
```

```php
use Pushery\Webhooks\Client\Jobs\ProcessWebhookJob;

class HandlePartnerWebhook extends ProcessWebhookJob
{
    public function handle(): void
    {
        // $this->webhookCall — the stored row
        // $this->message     — the parsed envelope

        // Not every producer sends JSON. Mollie posts a form body; some send XML.
        // `format` says how the body was read, and `readable()` is false only when
        // nothing read it — the one case where an empty payload means data is
        // missing rather than absent.
        if (! $this->message->format->readable()) {
            // $this->webhookCall->body() still returns the exact bytes.
            throw new RuntimeException('Unread delivery');
        }
    }
}
```

Point a route at it with the macro, which is registered only while the Client
layer is on:

```php
use Illuminate\Support\Facades\Route;

Route::webhooks('webhooks/partner', 'partner');
```

An authentic request is verified, de-duplicated, stored and dispatched to the
job. An invalid, expired or malformed signature is answered `401` and never
reaches it.

**Embed a self-service panel in a screen the application already has.** Each panel
has a stable alias, and a host that mounts its own page usually does not want the
portal's URLs beside it:

```php
// bootstrap/providers.php — FIRST, or the alias below does not exist
Pushery\Webhooks\Platform\SelfServicePortalServiceProvider::class,
```

```php
// config/webhooks.php
'platform' => ['self_service' => ['enabled' => true, 'register_routes' => false]],
```

```blade
<livewire:webhooks.self-service.endpoint-list />
```

With `register_routes` false the provider registers the components and mounts
nothing, and the panels drop the links they cannot resolve rather than failing to
render. The `manage-webhook-endpoints` gate still applies on every request.

**Check the operator console per action.** `Pushery\Webhooks\Livewire\SubscriptionManager`
and `DeliveryLog` are unscoped across every tenant and must sit behind an
operator-only gate of the host's. That page gate does not re-run on each Livewire
interaction, so set `admin.ability` when a revoked capability has to bite
immediately:

```php
'admin' => ['ability' => 'webhooks.operate'],
```

The action name (`create`, `edit`, `toggle`, `rotate`, `delete`, `redeliver`, `ping`)
is passed to the gate. Default `null` means no per-action check. It is not tenant
scoping.

⚠️ **If the capabilities come from spatie/laravel-permission, use `admin.abilities`
instead.** That package's `Gate::before` hook reads the first positional gate argument
as a guard name and shifts it off, so the action name turns into a guard nobody
defined and every action denies every operator — silently. An ability taken from the
map is authorized with no argument at all:

```php
'admin' => ['abilities' => ['*' => 'manage webhooks']],
```

`'*'` is the catch-all; an exact action key wins over it.

`rotate` is the one to reach for in an incident: it issues a new signing secret and
shows it once, while the previous secret keeps verifying until the rotation window
closes — so a leak is closed immediately without knocking the receiver offline.

**Send-only, with no database.** When the application wants nothing but the
signed, SSRF-guarded, retrying sender:

```php
// config/webhooks.php
'platform' => ['enabled' => false],
'server' => ['persistence' => ['enabled' => false]],
```

`PendingWebhook` keeps working — it needs only a queue — and no migration runs.

## Examples

A billing application that emits `invoice.paid` to one customer endpoint and
needs no inbound receiving, no portal and no dashboard:

```php
// config/webhooks.php — the whole configuration
'client' => ['enabled' => false],
'dashboard' => ['enabled' => false],
```

```php
use Pushery\Webhooks\Server\PendingWebhook;

class NotifyInvoicePaid
{
    public function handle(InvoicePaid $event): void
    {
        PendingWebhook::create()
            ->url($event->invoice->customer->webhook_url)
            ->payload([
                'type' => 'invoice.paid',
                'invoice_id' => $event->invoice->id,
                'amount' => $event->invoice->amount_cents,
            ])
            ->useSecret($event->invoice->customer->webhook_secret)
            ->dispatch();
    }
}
```

Platform stays on, so the delivery is recorded in the log and the customer's
endpoint gets health scoring for free. Turning Platform off as well would drop
the tables and keep the sender.

## Anti-Patterns

- Do not enable every layer to "see what it does". Each one that persists adds
  tables and queries; the gates exist so an application pays only for what it uses.
- Do not publish every migration tag. A published migration runs — publish only
  the layers the application actually switched on.
- Do not set `server.enabled=false` alone to stop outbound delivery. Platform
  boots Server regardless; set both.
- Do not verify inbound signatures by hand. The Client layer's pipeline handles
  verification, replay windows and de-duplication; a hand-rolled comparison is
  where timing leaks and replay holes come from.
- Do not `json_decode` the raw body yourself when `$this->message->payload` looks
  empty, and do not treat that empty payload as "the producer sent nothing". The
  pipeline already reads JSON and declared form bodies; check
  `$this->message->format` first. Acknowledging a delivery nobody read is what
  stops the producer ever sending it again, and it leaves no error behind.
- Do not bulk-register endpoints through the self-service portal. Three brakes ship
  **on**: `platform.self_service.registrations_per_minute` (10),
  `platform.self_service.replays_per_minute` (10) and
  `platform.test_ping.max_per_minute` (5, refused with
  `Pushery\Webhooks\Exceptions\TestPingThrottled`). They bound what a *person* repeats,
  and the replay one bounds how often a customer can make the server send a request to
  a URL they chose. An import belongs on `Webhooks::subscribe()`, which is not braked;
  `null` removes any of them if the application genuinely needs it gone.
- Do not "fix" a delivery list that shows only the last 30 days by widening the
  component property — it is clamped and cannot reach past the config. Both lists are
  bounded on purpose (`platform.deliveries.window_days`,
  `dashboard.deliveries.window_days`): the delivery log is partitioned by month, and a
  read with no lower bound on `created_at` visits every partition there is. Raise the
  config key if the application needs a longer window, or set it to `0` to remove the
  bound entirely.
- Do not document package internals here; keep this skill focused on adoption in
  Laravel applications, and link the deeper reference material instead.

## Further reading

Full documentation, including the complete `PendingWebhook` builder, every
shipped verification adapter, the self-service portal and the dashboard:
<https://docs.pushery.com/webhooks-for-laravel/>
