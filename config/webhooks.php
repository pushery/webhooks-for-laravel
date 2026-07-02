<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Event catalog
    |--------------------------------------------------------------------------
    |
    | The event types your application can emit. Each key is a type dispatched
    | with Webhooks\WebhookEvent::dispatch('type', $payload). The optional
    | 'schema' (a JSON Schema array) validates the payload before delivery when
    | 'validate_payloads' is enabled; 'example' and 'description' document the
    | shape for the management UI and your public API reference.
    |
    */

    'catalog' => [
        // 'invoice.paid' => [
        //     'description' => 'Fired when an invoice is paid in full.',
        //     'example' => ['invoice_id' => 'in_123', 'amount' => 4200],
        //     'schema' => [
        //         'type' => 'object',
        //         'required' => ['invoice_id', 'amount'],
        //         'properties' => [
        //             'invoice_id' => ['type' => 'string'],
        //             'amount' => ['type' => 'integer', 'minimum' => 1],
        //         ],
        //         'additionalProperties' => false,
        //     ],
        // ],
    ],

    // When enabled, an event whose type declares a 'schema' above is validated
    // against it before any delivery is created; a mismatch throws
    // Webhooks\Exceptions\InvalidPayloadException. Types without a schema, and
    // all events while this is false, pass through unchecked.
    'validate_payloads' => false,

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | How each webhook call is queued and retried. These values are handed to
    | spatie/laravel-webhook-server per call. 'queue' and 'connection' select
    | where delivery jobs run; a Redis-backed queue is recommended so that the
    | exponential backoff between retries does not block other work.
    |
    */

    'delivery' => [
        'queue' => 'default',
        'connection' => null,
        'tries' => 3,
        'timeout' => 5,
        'verify_ssl' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signature
    |--------------------------------------------------------------------------
    |
    | Every request is signed with an HMAC-SHA256 signature computed over
    | "{timestamp}.{body}" and sent in the header below as
    | "t=<unix>,v1=<signature>". The signed timestamp lets a consumer reject
    | replayed requests older than 'tolerance' seconds. During a secret rotation
    | both the current and previous secret are signed, so more than one "v1="
    | value may be present — a consumer accepts the request if any one verifies.
    |
    */

    'signature' => [
        'header_name' => 'Webhook-Signature',
        'tolerance' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | After this many consecutive final failures an endpoint is disabled
    | automatically and a Webhooks\Events\WebhookEndpointAutoDisabled event is
    | fired so you can notify its owner. A single successful delivery resets the
    | counter to zero.
    |
    */

    'circuit_breaker' => [
        'enabled' => true,
        'threshold' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-endpoint rate limit
    |--------------------------------------------------------------------------
    |
    | Caps how many deliveries a single subscription may enqueue per minute so a
    | slow or flooded endpoint cannot starve the queue for everyone else. Backed
    | by the cache store, so any driver (including 'array' in tests) works.
    |
    */

    'rate_limit' => [
        'enabled' => true,
        'max_per_minute' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoint guard (SSRF protection)
    |--------------------------------------------------------------------------
    |
    | Webhook URLs are attacker-influenced, so every endpoint is validated when
    | it is registered AND again immediately before each delivery (to defeat DNS
    | rebinding). By default all private, loopback, link-local, unique-local and
    | cloud-metadata addresses are refused. Use 'allowed_hosts' to permit a
    | specific internal host and 'blocked_hosts' to deny additional hosts.
    | Keep 'https_only' enabled outside local development.
    |
    */

    'endpoints' => [
        'https_only' => true,
        'block_private_networks' => true,
        'allowed_hosts' => [],
        'blocked_hosts' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery log retention
    |--------------------------------------------------------------------------
    |
    | The webhook_deliveries table is range-partitioned by month. The
    | webhooks:partition-maintenance command (scheduled daily) provisions the next
    | 'partition_months_ahead' months and drops partitions whose month is older
    | than 'retention_months'.
    |
    */

    'retention_months' => 3,

    'partition_months_ahead' => 3,

    /*
    |--------------------------------------------------------------------------
    | Horizon tags
    |--------------------------------------------------------------------------
    |
    | When true, each delivery job is tagged with its subscription and event
    | type, giving per-endpoint observability in Laravel Horizon out of the box.
    |
    */

    'horizon_tags' => true,

];
