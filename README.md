<p align="center">
  <a href="https://github.com/pushery/webhooks-for-laravel">
    <img src="art/header.png" alt="Webhooks for Laravel" width="100%">
  </a>
</p>

# Webhooks for Laravel

[![Latest Version](https://img.shields.io/packagist/v/pushery/webhooks-for-laravel.svg)](https://packagist.org/packages/pushery/webhooks-for-laravel)
[![PHP Version](https://img.shields.io/packagist/php-v/pushery/webhooks-for-laravel.svg)](https://packagist.org/packages/pushery/webhooks-for-laravel)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-blue.svg)](https://phpstan.org)
[![Code Style](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)
[![License](https://img.shields.io/packagist/l/pushery/webhooks-for-laravel.svg)](LICENSE)

Customer-configurable **outgoing** webhooks for Laravel. Your customers register
endpoints for the event types they care about; the package fans each event out to
every matching endpoint, signs it, retries it with backoff, and keeps a searchable,
partitioned delivery log with test-ping and one-click redelivery.

It builds on [spatie/laravel-webhook-server](https://github.com/spatie/laravel-webhook-server)
(which sends a single signed HTTP call with retries) and adds the parts that make it
a product: the subscription model, the delivery log, the event catalog, a versioned
Stripe-style signature with secret rotation, an SSRF guard, a circuit breaker, and an
optional management UI.

It is **not** an inbound webhook handler (for that, see spatie/laravel-webhook-client).

## Requirements

- PHP 8.4+ with `ext-curl`
- Laravel 13
- PostgreSQL 13+ (the delivery log uses `jsonb`, GIN indexes and declarative range partitioning)
- A queue worker is required; Redis is recommended so retry backoff does not block other work

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

## Quickstart

**1. Describe the events your application can emit** in `config/webhooks.php`:

```php
'catalog' => [
    'invoice.paid' => [
        'description' => 'Fired when an invoice is paid in full.',
        'example' => ['invoice_id' => 'in_123', 'amount' => 4200],
    ],
],
```

**2. Register an endpoint.** The URL is SSRF-validated and a signing secret is generated:

```php
use Webhooks\Facades\Webhooks;

$subscription = Webhooks::subscribe(
    owner: $team,                       // any Eloquent model, or null for a global endpoint
    url: 'https://example.com/webhooks',
    eventTypes: ['invoice.paid'],
);

$subscription->secret; // show this to the customer once — it signs their deliveries
```

**3. Emit an event.** It fans out to every active endpoint listening for the type:

```php
use Webhooks\WebhookEvent;

WebhookEvent::dispatch('invoice.paid', ['invoice_id' => 'in_123', 'amount' => 4200], tenant: $team);
```

Each subscriber receives a signed POST with this JSON body:

```json
{
  "id": "0192...-uuid",
  "type": "invoice.paid",
  "created_at": "2026-07-01T12:00:00+00:00",
  "data": { "invoice_id": "in_123", "amount": 4200 }
}
```

The `id` is stable across redeliveries, so consumers can deduplicate on it.

## Verifying the signature

Every request carries an HMAC-SHA256 signature over `"{timestamp}.{rawBody}"` in the
`Webhook-Signature` header, Stripe-style:

```
Webhook-Signature: t=1720000000,v1=5257a869e7...
```

A Laravel consumer can verify it with the shipped helper:

```php
use Webhooks\Signing\SignatureVerifier;

$valid = SignatureVerifier::verify(
    header: $request->header('Webhook-Signature'),
    body: $request->getContent(),   // the RAW request body
    secret: $endpointSecret,
);

abort_unless($valid, 400);
```

In any language: split the header on `,`, read `t` and each `v1`, reject if `t` is
older than your tolerance (default 300s), then compare `hmac_sha256("{t}.{body}", secret)`
against each `v1` in constant time. During a secret rotation more than one `v1` is
present — accept the request if any one verifies. Deliveries are re-signed at send
time, so queue latency never expires a legitimate signature.

## Security (SSRF)

Webhook URLs are attacker-influenced, so every endpoint is validated when it is
registered **and** again immediately before each delivery, with the connection pinned
to the validated IP address so a rebinding DNS record cannot redirect it elsewhere.
Private, loopback, link-local, unique-local, carrier-grade-NAT, multicast and
cloud-metadata (`169.254.169.254`) addresses are refused, redirects are not followed,
and TLS verification stays on. Configure `endpoints.https_only`, `allowed_hosts` and
`blocked_hosts` in `config/webhooks.php`. IPv6-only endpoints are refused (fail-closed).

## Reliability

- **Retries & backoff** are handled by spatie/laravel-webhook-server (configure `delivery.tries`, `delivery.timeout`).
- **Circuit breaker**: after `circuit_breaker.threshold` consecutive final failures an
  endpoint is auto-disabled and a `Webhooks\Events\WebhookEndpointAutoDisabled` event is
  fired; a single success resets the counter.
- **Events** you can listen for (no dependency is added — broadcast them over Reverb for a
  live dashboard if you like): `WebhookDeliverySucceeded`, `WebhookDeliveryFailed`,
  `WebhookEndpointAutoDisabled`.
- **Per-endpoint rate limit** (`rate_limit.max_per_minute`) stops one slow endpoint from starving the queue.
- **Horizon tags**: each delivery job is tagged with its subscription and event type.

## Payload validation (optional)

Give an event type a JSON Schema in the catalog and enable `validate_payloads`, and every
dispatched payload is checked against it **before any delivery is created** — a malformed
event never reaches a subscriber:

```php
// config/webhooks.php
'catalog' => [
    'invoice.paid' => [
        'description' => 'Fired when an invoice is paid in full.',
        'schema' => [
            'type' => 'object',
            'required' => ['invoice_id', 'amount'],
            'properties' => [
                'invoice_id' => ['type' => 'string'],
                'amount' => ['type' => 'integer', 'minimum' => 1],
            ],
            'additionalProperties' => false,
        ],
    ],
],
'validate_payloads' => true,
```

A payload that does not satisfy the schema throws `Webhooks\Exceptions\InvalidPayloadException`,
whose `->errors` holds the formatted violations. Event types without a schema (and every event
while `validate_payloads` is `false`) pass through unchecked, so the catalog stays a pure
documentation aid until you opt a type in. Validation uses [opis/json-schema](https://opis.io/json-schema).

## Secret rotation

Set a subscription's `previous_secret` alongside a new `secret`; both are signed (two
`v1=` values) so customers can update at their own pace, then clear `previous_secret`.

## Delivery log & retention

Deliveries are stored in a monthly range-partitioned table. The scheduled command
`webhooks:partition-maintenance` (registered to run daily) provisions upcoming
partitions and drops those older than `retention_months` — a cheap metadata operation
instead of a bulk `DELETE`. Ensure your scheduler is running.

## Management UI (optional)

The package ships optional Livewire management screens as **publishable stubs**. Register
the provider (it is not auto-registered, so the core stays headless):

```php
// bootstrap/providers.php
Webhooks\WebhooksUiServiceProvider::class,
```

Then embed the components in your own authorized, branded pages:

```blade
<livewire:webhooks-subscriptions />
<livewire:webhooks-deliveries />
```

They require `livewire/livewire`. Publish the Blade stubs and restyle them to match your
app — the stubs ship in **two variants**, publish exactly one:

```bash
composer require livewire/livewire

# Neutral Tailwind stubs — a blank canvas to restyle with any design system:
php artisan vendor:publish --tag=webhooks-ui

# …or stubs already built from Pushery's own design system, WireKit:
php artisan vendor:publish --tag=webhooks-ui-wirekit
```

Both variants render the same two components and publish to the same
`resources/views/vendor/webhooks/livewire` path, so you own and restyle them from there.
The WireKit variant needs [`pushery/wirekit`](https://wirekit.app) installed and its
`@source` included in your Tailwind build so the component utilities compile.

## Configuration

Every option is documented inline in `config/webhooks.php`: the event catalog, delivery
(tries/timeout/queue), signature header + tolerance, circuit breaker, rate limit, SSRF
endpoint rules, retention, and Horizon tags.

## Testing

```bash
composer test
```

## Security

Please review the [security policy](SECURITY.md) and report vulnerabilities privately
rather than opening a public issue.

## Built by Pushery

This package is built and maintained by [Pushery](https://www.pushery.com) — a
Berlin-based studio building Laravel applications, SaaS products, and open-source
tools.

Building a Laravel UI? [WireKit](https://wirekit.app), Pushery's open-source
Livewire component kit, gives you a polished component library out of the box.
Browse the rest of our work at [pushery.com](https://www.pushery.com).

## Versioning

This package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
