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

The service providers are registered automatically through package discovery.

### 2. Decide which layers the application needs

Five layers sit on a shared crypto/transport core, each with one switch:

| Layer | What it does | Default |
| --- | --- | --- |
| Core | Signing dialects, SSRF guard, HTTP transport | Always on |
| Server | Outbound delivery — sign, queue, retry, back off | On |
| Platform | Endpoint subscriptions, event fan-out, self-service portal | On |
| Client | Inbound receiving — verify, de-duplicate, store, queue | Off |
| Dashboard | Customer-facing observability UI over the delivery log | Off |

Two dependencies between the gates bite silently:

- **Platform implies Server.** Fan-out delivers *through* the Server engine, so
  `platform.enabled=true` boots Server regardless of `server.enabled`. To stop
  outbound delivery entirely, set **both** to `false`.
- **Dashboard requires Platform.** The dashboard reads Platform's
  `webhook_deliveries` log, whose migration only runs while `platform.enabled=true`.

### 3. Configure

```bash
php artisan vendor:publish --tag=webhooks-config
```

Every option in `config/webhooks.php` is documented inline. Publishing is
optional — the package merges its own defaults, so publish only when overriding
something.

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
use Webhooks\Server\PendingWebhook;

PendingWebhook::create()
    ->url('https://example.com/webhooks')
    ->payload(['invoice_id' => 'in_123', 'amount' => 4200])
    ->useSecret('whsec_your_endpoint_secret')
    ->dispatch();
```

`dispatch()` returns the queued `WebhookDeliveryData`. Its `messageId` is stable
across retries and is the correlation key to record against the application's own
log. Use `->dispatchSync()` to send inline instead of queueing.

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
use Webhooks\Client\Jobs\ProcessWebhookJob;

class HandlePartnerWebhook extends ProcessWebhookJob
{
    public function handle(): void
    {
        // $this->webhookCall — the stored row
        // $this->message     — the parsed envelope
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
use Webhooks\Server\PendingWebhook;

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
- Do not document package internals here; keep this skill focused on adoption in
  Laravel applications, and link the deeper reference material instead.

## Further reading

Full documentation, including the complete `PendingWebhook` builder, every
shipped verification adapter, the self-service portal and the dashboard:
<https://docs.pushery.com/webhooks-for-laravel/>
