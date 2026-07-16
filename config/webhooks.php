<?php

declare(strict_types=1);
use Webhooks\Core\Signing\StandardWebhooksScheme;
use Webhooks\Models\WebhookDelivery;

return [

    /*
    |--------------------------------------------------------------------------
    | Database — where the package stores its tables
    |--------------------------------------------------------------------------
    |
    | Null uses the application's own default connection. Point this at a dedicated
    | connection to keep the package's tables somewhere other than the app database —
    | the common case being a MySQL application that keeps these PostgreSQL-shaped
    | tables on a PostgreSQL side-car. The named connection must be one the package
    | supports (PostgreSQL or MySQL 8.4+); run `php artisan webhooks:preflight
    | --connection=<name>` to check it.
    |
    */

    'database' => [
        'connection' => env('WEBHOOKS_DB_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled maintenance
    |--------------------------------------------------------------------------
    |
    | The package schedules its own maintenance against the DEFAULT connection:
    | partition rolling, rotated-secret revocation, the dashboard rollup refresh,
    | endpoint-health sweeps, and log pruning. A single-database app wants this on
    | (the default).
    |
    | A DB-PER-TENANT host must turn it OFF: set 'enabled' => false and the package
    | registers NOTHING in the scheduler, then run the commands yourself inside your
    | own tenant loop (loop over active tenants, run each command on that tenant's
    | connection). Left on, the maintenance would run only on the central database and
    | NEVER on a tenant's — the delivery log grows unbounded and the dashboard reads
    | empty. The commands themselves are unchanged either way.
    |
    */

    'schedule' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Core — signing, SSRF and HTTP transport (always on)
    |--------------------------------------------------------------------------
    |
    | The shared crypto + egress layer of the in-house engine. Standard Webhooks
    | is the default signature dialect. The SSRF policy here is the single source
    | of truth — no other layer mirrors it. Every outbound URL is vetted against
    | it at registration time and again immediately before each delivery.
    |
    */

    'core' => [
        // The dialect every outbound delivery is signed with unless a single call
        // overrides it with ->signUsing(). Must implement
        // Webhooks\Core\Signing\SignatureScheme.
        'signing' => [
            'scheme' => StandardWebhooksScheme::class,
        ],

        // The egress policy. Read this before changing it: two of the four keys
        // WEAKEN the guard rather than tighten it.
        //
        //   https_only             Refuse a plaintext http:// endpoint.
        //   block_private_networks The guard itself. Set to false and NOTHING is
        //                          classified: private, loopback, link-local and
        //                          cloud-metadata (169.254.169.254) destinations all
        //                          become reachable, and no IP is pinned. Leave true.
        //   blocked_hosts          The RESTRICTIVE list — a host named here is always
        //                          refused. This is the one to add to.
        //   allowed_hosts          A DANGEROUS OPT-OUT, not an allowlist. A host named
        //                          here skips DNS resolution, skips the private /
        //                          loopback / metadata IP classification AND skips IP
        //                          pinning — so that host may resolve to an internal
        //                          address and its DNS record may rebind between the
        //                          check and the connection. Use it only for a known
        //                          internal endpoint whose risk you accept, never as a
        //                          way to "tighten" egress. Empty means nothing bypasses
        //                          the guard, which is what you want.
        'ssrf' => [
            'https_only' => (bool) env('WEBHOOKS_HTTPS_ONLY', true),
            'block_private_networks' => true,
            'allowed_hosts' => [],
            'blocked_hosts' => [],
        ],

        // Publish the fixed set of source IPs your deliveries egress from, so a
        // consumer can allowlist them on its firewall. 'published_ips' is the list
        // the webhooks:egress-ips command prints (json/txt/md). 'enabled' is the
        // master gate: while it is false a configured 'proxy' is IGNORED, so the proxy
        // can be stood down without deleting its URL. When it is true and 'proxy' is a
        // non-empty string, every outbound delivery is routed through it — and note
        // that this weakens the guard: the SSRF pin (CURLOPT_RESOLVE) binds a DIRECT
        // connection to the vetted IP, but a proxy resolves the host itself, so the pin
        // is NOT enforced through a proxy. The operator's proxy
        // must enforce egress control itself. Off by default.
        'egress' => [
            'enabled' => (bool) env('WEBHOOKS_EGRESS_ENABLED', false),
            'published_ips' => [],
            'proxy' => env('WEBHOOKS_EGRESS_PROXY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server — the outbound delivery engine (default on)
    |--------------------------------------------------------------------------
    |
    | Every setting here is the DEFAULT for each outbound call; the fluent builder
    | (WebhookCall) overrides any of them per delivery.
    |
    | Where delivery jobs run, and how they retry. no_retry_on_4xx keeps a 400/410
    | from being retried for hours (408/425/429 are still retried). large_payload
    | offloads a delivery-log payload larger than 'threshold' bytes to the 'disk'
    | Storage disk, leaving the row a pointer plus the body sha256 (off by default,
    | so every payload stays inline). Offloaded objects are content-addressed (identical
    | bodies share one object) and are NOT deleted when the row is pruned or its
    | partition is dropped — retention removes rows only. Reclaim them with a lifecycle
    | policy on the 'disk' that expires objects by last-modified age of at least your
    | retention window; every offload re-writes the object, so an object past that age
    | has no live row and is safe to expire. http_verb, connect_timeout and timeout shape the
    | request itself; verify_ssl toggles TLS certificate verification on it; and
    | horizon_tags tags each delivery job with its subscription and event type for
    | per-endpoint observability in Laravel Horizon.
    |
    | The backoff is exponential with full jitter (base * 2^(n-1), capped). When
    | respect_retry_after is on and an endpoint answers a retryable 429 or 503 with a
    | Retry-After header, that hint is obeyed instead of the jittered delay (clamped to
    | retry_after_cap, its own ceiling — see below); both delta-seconds and an HTTP-date
    | form are understood.
    |
    */

    'server' => [
        // The outbound delivery engine. NOTE: the Platform layer delivers its fan-out
        // THROUGH this engine, so platform.enabled => true boots the Server regardless
        // of this switch. To stop outbound delivery entirely, set BOTH to false.
        'enabled' => (bool) env('WEBHOOKS_SERVER_ENABLED', true),
        'queue' => env('WEBHOOKS_SERVER_QUEUE', 'default'),
        'connection' => env('WEBHOOKS_SERVER_CONNECTION'),

        // Asymmetric Ed25519 (v1a) signing, off by default. When enabled EVERY outbound
        // delivery is signed with this one base64 Ed25519 secret key instead of the
        // endpoint's shared secret, and each receiver verifies with the matching public
        // key (pinned statically, or served from a JWKS endpoint) — so a leak of what a
        // receiver stores can never forge a delivery. Enabling it without a secret_key
        // is a hard error rather than a silent fall-back to HMAC. The symmetric Standard
        // Webhooks HMAC (core.signing.scheme) stays the default; generate a keypair with
        // `php artisan webhooks:ed25519-keygen`.
        //
        // canonicalize applies a deterministic, sorted-key JSON serialization (an
        // RFC 8785-style canonical form) to the delivered body before it is signed,
        // so a receiver that re-canonicalizes reproduces the exact signed bytes
        // regardless of key order. Off by default: signing the exact bytes you send
        // is already correct, and turning this on changes the wire body, so a
        // receiver comparing raw bytes must canonicalize too. Deliberately conservative.
        'signing' => [
            'canonicalize' => (bool) env('WEBHOOKS_CANONICALIZE_JSON', false),
            'ed25519' => [
                'enabled' => false,
                'secret_key' => env('WEBHOOKS_ED25519_SECRET_KEY'),
            ],
        ],

        'http_verb' => 'post',
        'connect_timeout' => 3,
        'timeout' => 5,
        'tries' => 3,

        // The retry schedule. 'base' and 'cap' bound the jittered exponential delay; the
        // cap's default keeps a released job under an SQS visibility timeout.
        //
        // retry_after_cap is a SEPARATE ceiling, and deliberately so: it is the longest
        // wait this queue can hold a job for, while an endpoint's rate-limit window
        // (429/503 + Retry-After) is routinely much longer than any visibility timeout.
        // When an endpoint asks for longer than the cap, the delivery comes back at the
        // cap and that wait is NOT charged against 'tries' — up to retry_after_max_deferrals
        // times — so an endpoint answering "Retry-After: 3600" is still there to receive
        // the webhook when its window elapses, instead of the delivery being exhausted
        // half an hour earlier. Raise retry_after_cap on a queue that can hold longer
        // delays (Redis, database) to obey such a hint exactly.
        'backoff' => [
            'base' => 10,
            'cap' => 900,
            'respect_retry_after' => true,
            'retry_after_cap' => 900,
            'retry_after_max_deferrals' => 6,
        ],
        'no_retry_on_4xx' => true,
        'retryable_4xx' => [408, 425, 429],

        // How many bytes of an endpoint's response are kept on the delivery log for
        // diagnosis. Everything beyond it is read off the wire and discarded, never
        // buffered — a tenant-supplied endpoint that answers with an endless stream can
        // cost a worker its timeout, but never its memory.
        'response_capture_bytes' => 65536,

        // Opt-in standalone delivery log for the Server layer used WITHOUT the
        // Platform layer. When the Platform layer runs it owns the webhook_deliveries
        // table and its full delivery lifecycle, so this stays off — enabling both
        // would double-log. Turn it on only when a consumer drives WebhookCall /
        // WebhookSender directly and still wants a persisted, prunable record of every
        // delivery: a listener then upserts each attempt into webhook_server_deliveries
        // keyed by the message id, and rows older than 'prune_after_days' are removed by
        // the scheduled model:prune command. Off by default; nothing is created or
        // written while disabled.
        'persistence' => [
            'enabled' => (bool) env('WEBHOOKS_SERVER_PERSISTENCE_ENABLED', false),
            'prune_after_days' => 30,
        ],

        'large_payload' => ['enabled' => false, 'threshold' => 262144, 'disk' => 's3'],
        'verify_ssl' => true,
        'horizon_tags' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform — catalog, validation, delivery-log lifecycle (default on)
    |--------------------------------------------------------------------------
    |
    | The management layer around the engine: the event catalog your application
    | can emit, optional payload validation, the per-endpoint circuit breaker and
    | rate limit, and the delivery-log retention window.
    |
    | The 'catalog' lists the event types dispatched with
    | Webhooks\WebhookEvent::dispatch('type', $payload). The optional 'schema' (a
    | JSON Schema array) validates the payload before delivery when
    | 'validate_payloads' is enabled; 'example' and 'description' document the
    | shape for the management UI and your public API reference. A type without a
    | schema, and every event while 'validate_payloads' is false, passes through
    | unchecked; a mismatch throws Webhooks\Exceptions\InvalidPayloadException.
    |
    | After 'circuit_breaker.threshold' consecutive final failures an endpoint is
    | disabled automatically and a Webhooks\Events\WebhookEndpointAutoDisabled
    | event is fired; a single successful delivery resets the counter. The
    | 'rate_limit' caps how many deliveries a single subscription may enqueue per
    | minute, backed by the cache store so any driver (including 'array' in tests)
    | works. The webhook_deliveries table is range-partitioned by month: the
    | webhooks:partition-maintenance command (scheduled daily) provisions the next
    | 'partition_months_ahead' months and drops partitions older than
    | 'retention_months'.
    |
    */

    'platform' => [
        'enabled' => (bool) env('WEBHOOKS_PLATFORM_ENABLED', true),

        // The primary-key type of the models that OWN webhook subscriptions: 'bigint'
        // (default), 'uuid' or 'ulid'. It fixes the storage type of the denormalised
        // owner_id column across the subscriptions table, the delivery log AND the
        // dashboard rollup — the three must match or the tenant join breaks — so set it
        // to match your owner models and migrate; changing it on a populated database is a
        // schema migration, not a runtime toggle. A subscription with a null (global)
        // owner works under every setting.
        'owner_key_type' => env('WEBHOOKS_OWNER_KEY_TYPE', 'bigint'),

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

        'validate_payloads' => false,

        'circuit_breaker' => [
            'enabled' => true,
            'threshold' => 10,
        ],

        // Prefix wildcards in a subscription's event types. Off by default (exact matching).
        // When on, a subscription may list 'order.*' and receives every event under that
        // prefix — a concrete 'order.line.added' is delivered to subscribers of
        // 'order.line.added', 'order.line.*' and 'order.*' (one prefix per dot). Each arm is
        // still an indexed JSON-containment lookup, so the fan-out stays an index scan.
        'wildcards' => false,

        // Shapes a single endpoint's traffic — it does not throw messages away. A
        // subscription over its per-minute allowance still gets its delivery-log row and
        // its delivery; the delivery is simply enqueued with a delay, so a burst is spread
        // across the following minutes at max_per_minute instead of arriving at once. A
        // Webhooks\Events\WebhookDeliveryRateLimited event fires for every delivery that
        // is deferred this way, so the shaping is visible rather than a silent gap.
        'rate_limit' => [
            'enabled' => true,
            'max_per_minute' => 60,
        ],

        // How long an endpoint's PREVIOUS signing secret keeps verifying after a
        // rotation. Both secrets sign each delivery during the window, so a consumer can
        // migrate without dropping a webhook; once it closes, the old secret is cleared
        // from the row and can never sign again. That expiry is the entire point of
        // rotating: a window that never closes revokes nothing. Set to 0 to revoke the
        // old secret the instant it is rotated away.
        'secret_rotation_window_hours' => 24,

        'retention_months' => 3,

        'partition_months_ahead' => 3,

        // Let a tenant manage its OWN webhook endpoints (register, edit, rotate the
        // secret, delete) rather than only an operator. Off by default. The portal
        // SHIPS its screens: full-page Livewire panels — the endpoint list, the
        // create/edit form, the signing-secret panel, the health matrix and the
        // payload-transform editor — mounted at 'route_prefix' behind 'middleware'.
        //
        // TWO things switch it on, and both are required: set 'enabled' to true AND
        // register Webhooks\Platform\SelfServicePortalServiceProvider in the host's
        // bootstrap/providers.php — it is NOT auto-registered. It needs livewire/livewire
        // (and pushery/wirekit to render as shipped); a host on another UI kit publishes
        // the views with --tag=webhooks-self-service-views and restyles them.
        //
        // Enabling it activates the manage-webhook-endpoints gate + the row-level
        // WebhookSubscriptionPolicy, both scoped so a tenant only ever sees the
        // endpoints it owns. 'secret_reveal_ttl' is how many seconds a freshly created
        // or rotated secret stays revealable; 'allow_delete' toggles endpoint deletion;
        // 'max_endpoints_per_tenant' caps how many a single tenant may register
        // (null = unlimited).
        'self_service' => [
            'enabled' => (bool) env('WEBHOOKS_SELF_SERVICE_ENABLED', false),
            'middleware' => ['web', 'auth'],
            'route_prefix' => 'webhooks/endpoints',
            'secret_reveal_ttl' => 60,
            'allow_delete' => true,
            'max_endpoints_per_tenant' => null,
        ],

        // Endpoint health scoring. Each active endpoint earns a 0-100 health score
        // blended from its recent delivery history: the success rate, a latency
        // penalty as p95 duration approaches 'latency_budget_ms', and a penalty as
        // the consecutive-failure streak approaches 'consecutive_penalty_at'. The
        // 'weights' set each signal's share of the blend; 'thresholds' maps a score
        // onto a healthy / degraded / failing band (below the degraded cut-off is
        // failing, and no recent history at all is unknown); 'window_hours' is how
        // far back the history reads. The webhooks:refresh-endpoint-health command
        // recomputes and caches the score on demand. When 'enabled' is true a
        // finished delivery also refreshes its own endpoint's cached score
        // automatically AND the same command is scheduled on the 'refresh' cadence to
        // sweep every active endpoint — so an endpoint whose traffic dries up still
        // decays to its true band instead of freezing on the last score a delivery
        // left. Off by default, so the cached columns move only when you run the
        // command. The score query itself always works regardless. 'refresh' is a
        // schedule-frequency token (everyMinute, everyFiveMinutes, everyFifteenMinutes,
        // everyThirtyMinutes, hourly, …); an unknown token falls back to fifteen
        // minutes rather than silently never running.
        'health' => [
            'enabled' => (bool) env('WEBHOOKS_HEALTH_ENABLED', false),
            'refresh' => 'everyFifteenMinutes',
            'window_hours' => 24,
            'latency_budget_ms' => 2000,
            'consecutive_penalty_at' => 5,
            // When delivery-driven refresh is enabled, a busy endpoint would otherwise
            // recompute its full percentile window on every finished delivery. This
            // debounces that: the recompute is skipped when the cached score is younger
            // than this many seconds, leaning on the scheduled command for freshness.
            // Set to 0 to recompute on every finished delivery.
            'refresh_min_interval_seconds' => 60,
            'weights' => [
                'success' => 0.7,
                'latency' => 0.15,
                'consecutive' => 0.15,
            ],
            'thresholds' => [
                'healthy' => 90,
                'degraded' => 60,
            ],
        ],

        // Per-endpoint payload transformation and versioning. Off by default: while
        // disabled, every endpoint receives the raw event payload unchanged (today's
        // behavior). When enabled, an endpoint that carries a 'payload_version' and/or
        // a stored 'transform' receives a reshaped body — a safe, declarative mapping
        // (include / exclude / rename / rewrap, plus a stamped payload_version) applied
        // to the event data BEFORE the body is signed, so the transformed bytes are the
        // exact signed-and-sent bytes. Two endpoints subscribed to the same event with
        // different versions therefore receive different bodies. 'versions' declares
        // reusable default rule sets an endpoint can inherit by naming a version instead
        // of storing its own transform.
        'payload_versioning' => [
            'enabled' => (bool) env('WEBHOOKS_PAYLOAD_VERSIONING_ENABLED', false),
            'versions' => [
                // 'v2' => [
                //     'exclude' => ['internal_note'],
                //     'rename' => ['invoice_id' => 'id'],
                //     'rewrap' => 'data',
                // ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Client — the inbound receiving handler (default off)
    |--------------------------------------------------------------------------
    |
    | Receives, verifies and stores incoming webhooks. Off by default — an app
    | that only sends never pays for a receiver. Enable it, then declare one entry
    | per producer under 'configs' and point a route at it with the
    | Route::webhooks($url, $name) macro; the $name selects the matching entry.
    |
    | Each entry defaults to the Standard Webhooks dialect, so it verifies any
    | Standard Webhooks producer — including this package's own Server layer — out
    | of the box. Set 'scheme' => 'auto' to state that first-party intent
    | explicitly; it resolves to the same default scheme with no extra plumbing.
    |
    | An invalid signature responds with 'invalid_status' (401 by default), never
    | 500: a request that can never verify must not tell the sender to retry. The
    | signed timestamp is checked against 'tolerance_seconds' for replay
    | protection, and a repeated 'webhook_id' is de-duplicated so an at-least-once
    | sender (including our own Server on retry) is never processed twice.
    |
    | 'process' is either a single job class (extend
    | Webhooks\Client\Jobs\ProcessWebhookJob — it receives the stored call and the
    | parsed envelope) or a [event-type => job class] map for per-type routing.
    | 'store_headers' controls which request headers are persisted ('*' for all, a
    | list of names, or [] for none); the names in 'redact' (plus Authorization and
    | Cookie always) are masked before storage. Stored calls are pruned after
    | 'delete_after_days' days by the scheduled model:prune command.
    |
    | 'rate_limit' throttles a single source with a token bucket
    | (['max_attempts' => 60, 'decay_seconds' => 60]); an authentic request over the
    | limit is answered 429 with a Retry-After header and is neither stored nor
    | dispatched, while a forged one never counts (verification runs first). Omit the
    | key (or leave it null) to receive without limit. 'large_payload' offloads a body
    | larger than 'threshold' bytes to the 'disk' Storage disk, keeping only a pointer
    | plus the body sha256 in the row; rehydrate the full bytes with $call->body().
    | Offloaded objects are content-addressed and are NOT deleted by 'delete_after_days'
    | pruning (which removes rows only) — reclaim them with a lifecycle policy on the
    | 'disk' that expires objects by last-modified age of at least 'delete_after_days';
    | every offload re-writes the object, so an object past that age is unreferenced.
    |
    */

    'client' => [
        'enabled' => (bool) env('WEBHOOKS_CLIENT_ENABLED', false),

        'raw_body_capture' => true,

        'configs' => [
            // [
            //     'name' => 'stripe',
            //     'secret' => env('STRIPE_WEBHOOK_SECRET'),
            //     'scheme' => StandardWebhooksScheme::class, // or 'auto' for first-party
            //     // Asymmetric verification: set the scheme to Ed25519Scheme and supply
            //     // the producer's public key either as a static base64 'secret'
            //     // (whpk_… / raw base64), or via a JWKS endpoint of OKP/Ed25519 keys.
            //     // The JWKS document is fetched through the SSRF guard and cached for
            //     // 'cache_ttl' seconds; 'kid' pins one key, otherwise the current plus
            //     // previous key (the rotation window) are tried.
            //     'scheme' => \Webhooks\Core\Signing\Ed25519Scheme::class,
            //     'jwks' => ['url' => env('STRIPE_JWKS_URL'), 'cache_ttl' => 3600, 'kid' => null],
            //     // Authenticity that is NOT a signature over the bytes — a provider API
            //     // callback (Mollie) or a cert chain (PayPal). A verifier is
            //     // container-resolved, takes precedence over 'scheme', and makes
            //     // 'secret' optional. See Webhooks\Client\Verification\InboundVerifier.
            //     'verifier' => \App\Webhooks\MollieVerifier::class,
            //     // Header names the producer uses. Applies to ANY header-overridable
            //     // scheme, not just the two above — set 'signature' to bind e.g.
            //     // PlainHmacScheme to SendCloud's 'Sendcloud-Signature' from config alone.
            //     // A key you omit keeps the scheme's own default (GitHub's stays
            //     // X-Hub-Signature-256); StripeScheme's header is fixed and ignores this.
            //     'signature_headers' => [
            //         'id' => 'webhook-id',
            //         'timestamp' => 'webhook-timestamp',
            //         'signature' => 'webhook-signature',
            //     ],
            //     'tolerance_seconds' => 300,
            //     'invalid_status' => 401,
            //     // Three swappable seams, each defaulting to the class shown. 'profile'
            //     // decides which calls are processed and stored at all (filter out the
            //     // noise a producer sends); 'response' decides what the producer gets
            //     // back on success (status, body, headers); 'model' is the Eloquent
            //     // model the received call is stored as — point it at your own (or at
            //     // Webhooks\Search\SearchableWebhookCall) to add columns or indexing.
            //     'profile' => \Webhooks\Client\Profiles\ProcessEverythingWebhookProfile::class,
            //     'response' => \Webhooks\Client\Responses\DefaultRespondsTo::class,
            //     'model' => \Webhooks\Client\Models\WebhookCall::class,
            //     'process' => \App\Jobs\HandleStripeWebhook::class,
            //     // 'process' => [
            //     //     'invoice.paid' => \App\Jobs\HandleInvoicePaid::class,
            //     //     '*'            => \App\Jobs\HandleUnknownEvent::class,
            //     // ],
            //     'store_headers' => [],
            //     'redact' => ['Authorization', 'Cookie'],
            //     // Idempotency driver. 'redis+db' (the default) runs the cache fast path
            //     // in front of the partial-unique store — one cache read absorbs a
            //     // retry storm before it reaches the database. 'db' skips the cache and
            //     // relies on the partial-unique index alone: slower under a burst, but
            //     // it needs no cache store and cannot be defeated by a cache eviction.
            //     'dedupe' => 'redis+db',
            //     // Where the idempotency key comes from. Unset = the id header (the
            //     // Standard-Webhooks default). Providers with no delivery-id header
            //     // (Stripe's evt_… is in the body; Mollie/SendCloud send none) need it
            //     // read elsewhere, or dedupe silently does nothing:
            //     //   'dedupe_id' => 'header:X-Delivery-Id'   // an arbitrary header
            //     //   'dedupe_id' => 'body:data.object.id'    // a dotted path into the body
            //     //   'dedupe_id' => \App\Webhooks\MyDedupeKey::class // a DedupeKeyResolver
            //     'dedupe_id' => 'body:id',
            //     'rate_limit' => ['max_attempts' => 60, 'decay_seconds' => 60],
            //     'large_payload' => ['enabled' => false, 'threshold' => 262144, 'disk' => 's3'],
            // ],
        ],

        'delete_after_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard — the customer-facing observability read model (default off)
    |--------------------------------------------------------------------------
    |
    | The folded-in, opt-in analytics layer over the delivery log. It reads; it
    | records nothing. Its provider (Webhooks\Dashboard\WebhooksDashboardServiceProvider)
    | is NOT auto-registered — enable 'enabled' AND register the provider to boot the
    | hourly materialized view, the webhooks:refresh-metrics command and its schedule.
    | The Livewire/WireKit panels are the presentation layer on top of this read model:
    | rendering them as shipped needs livewire/livewire and pushery/wirekit (both
    | Composer suggestions), and a host on another UI kit publishes the views with
    | --tag=webhooks-dashboard-views and restyles them.
    |
    | It READS THE PLATFORM LAYER'S delivery log (see 'source_model'), whose table is
    | migrated only while platform.enabled is true — so the dashboard requires the
    | Platform layer. A host that points 'source_model' at its own log model owns that
    | table itself.
    |
    | 'source_model' is the read surface the metrics are computed from — the delivery
    | log model — so a host that swaps in its own log model points the dashboard at it
    | here. Every query is tenant-scoped: the owner is resolved by DashboardScope, which
    | a host overrides with DashboardScope::resolveUsing(). 'windows' are the selectable
    | ranges and 'poll_interval' the panel refresh cadence.
    |
    | 'percentiles.driver' selects the latency-percentile strategy. 'live' (Tier 1,
    | the default) computes percentile_cont over the raw rows in the bounded window,
    | accurate on stock PostgreSQL to low millions of rows per tenant and needing no
    | extension. 'tdigest' (Tier 2) is the high-volume path: it stores a per-bucket
    | latency t-digest in the hourly rollup and merges those digests across the window
    | with rollup() in O(buckets). It requires the PostgreSQL tdigest extension —
    | install it once (CREATE EXTENSION tdigest;) and re-run the dashboard migrations
    | so the rollup gains its latency_digest column; selecting 'tdigest' without the
    | extension raises one clear, actionable error rather than a cryptic SQL failure.
    | 'metrics.refresh' is the materialized-view refresh cadence.
    |
    | 'expose_json_api' (default false) adds a read-only JSON metrics endpoint serving
    | the same read model the panels render, so a host can drive its own charts, status
    | page or alerting from the dashboard's numbers. It mounts at 'api_path' under the
    | dashboard 'prefix' (GET /webhooks/api/metrics by default, route name
    | webhooks.dashboard.metrics), behind the same 'middleware' and the same
    | view-webhook-dashboard gate as the page, scoped to the acting tenant. It takes a
    | ?window= from 'windows' (an unsupported one is a 422) and returns aggregates only —
    | the KPI counts, the retry rate, the latency percentiles, the hourly buckets and the
    | busiest event types. No delivery rows, payloads, headers or secrets are exposed.
    | While the flag is false the route is not registered at all.
    |
    */

    'dashboard' => [
        // Requires the Platform layer: the dashboard reads Platform's delivery log
        // (see 'source_model'), whose table exists only while platform.enabled is true.
        'enabled' => (bool) env('WEBHOOKS_DASHBOARD_ENABLED', false),
        'prefix' => 'webhooks',
        'middleware' => ['web', 'auth', 'can:view-webhook-dashboard'],
        // Operator mode: read the GLOBAL, owner-less endpoints (subscriptions registered with
        // a null owner) instead of scoping to the acting tenant. Turn this on for a single
        // operator dashboard over your own global endpoints; leave it off (the default) for a
        // per-tenant customer dashboard. It shows global rows to everyone the
        // view-webhook-dashboard gate admits, so gate that ability to operators.
        'operator' => (bool) env('WEBHOOKS_DASHBOARD_OPERATOR', false),
        'source_model' => WebhookDelivery::class,
        'windows' => ['24h', '7d', '30d'],
        'poll_interval' => '30s',
        'percentiles' => [
            'driver' => 'live',
        ],
        'metrics' => [
            'refresh' => 'everyFiveMinutes',
        ],
        'expose_json_api' => false,
        'api_path' => 'api/metrics',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pulse — the internal-ops delivery monitor (default off)
    |--------------------------------------------------------------------------
    |
    | A single-view, gated Laravel Pulse card for YOUR engineers — throughput,
    | failure rate and latency of outbound deliveries, broken down by event type.
    | This is the internal monitor, distinct from the multi-tenant customer
    | dashboard above: it reuses Pulse's own sampling and aggregation rather than
    | the dashboard's materialized view.
    |
    | Strictly opt-in and off by default. Its provider
    | (Webhooks\Pulse\WebhookPulseServiceProvider) is NOT auto-registered: register
    | it in a host app, set 'enabled' to true, AND require laravel/pulse (a Composer
    | suggestion). With any of those absent nothing boots — the recorder never
    | listens and the card is never registered. Once enabled, add the card to the
    | Pulse dashboard with <livewire:webhooks.pulse.deliveries />.
    |
    */

    'pulse' => [
        'enabled' => (bool) env('WEBHOOKS_PULSE_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search — optional full-text index over the logs (default off)
    |--------------------------------------------------------------------------
    |
    | Makes the outbound delivery log and the inbound call log optionally
    | searchable through Laravel Scout. Off by default and adds no dependency:
    | laravel/scout is a Composer suggestion, so nothing is indexed and no engine
    | is contacted until a host installs Scout and sets 'enabled' to true. Use the
    | ready-made Webhooks\Search\SearchableWebhookDelivery / SearchableWebhookCall
    | models (point dashboard.source_model and a client config's 'model' at them),
    | or apply the Webhooks\Search\SearchableDelivery / SearchableCall trait to your
    | own model. While 'enabled' is false the models report shouldBeSearchable() as
    | false, so no row is ever written to an index.
    |
    | Only queryable, non-sensitive fields are indexed — the event type, the
    | endpoint URL (outbound) or source (inbound), the status, the owner/tenant id,
    | the timestamp, and a short payload excerpt capped at 'payload_excerpt_chars'
    | characters. A payload offloaded to a Storage disk is never indexed verbatim;
    | its excerpt is left empty so the large body is never copied into the index.
    |
    | The search ENGINE is Scout's own setting, not one of ours: pick it in
    | config/scout.php ('database' needs no extra service; 'meilisearch' needs a running
    | Meilisearch plus MEILISEARCH_HOST / MEILISEARCH_KEY). For strict multi-tenant
    | isolation, always constrain a query by the owner/tenant column — the shipped
    | searchFor* helpers do this.
    |
    */

    'search' => [
        'enabled' => (bool) env('WEBHOOKS_SEARCH_ENABLED', false),
        'payload_excerpt_chars' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenTelemetry — optional delivery tracing seam (default off)
    |--------------------------------------------------------------------------
    |
    | A dependency-free seam for emitting an OpenTelemetry span per finished
    | delivery. Off by default and pulls in no tracing SDK: the default
    | Webhooks\Server\Telemetry\SpanEmitter binding is a no-op, so with 'enabled'
    | false nothing is emitted and no collector is needed. To use it, bind your own
    | SpanEmitter implementation (forwarding Webhooks\Server\Telemetry\DeliverySpanAttributes
    | to your OpenTelemetry tracer) and set 'enabled' to true; a listener then maps
    | each succeeded / finally-failed delivery to a span name plus attributes (event
    | type, status, duration, attempt, HTTP status code) and hands it to your emitter.
    |
    */

    'otel' => [
        'enabled' => (bool) env('WEBHOOKS_OTEL_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | UI — presentation of the package's own full-page screens
    |--------------------------------------------------------------------------
    |
    | These settings govern the two layouts the package ships itself: the dashboard and
    | the self-service endpoint portal. They do not touch the publishable stubs, which
    | render inside the host's own layout.
    |
    | 'theme' picks the color scheme those layouts render in. WireKit's dark tokens live
    | behind a 'dark' class on the document root and never switch on the operating
    | system's preference by themselves — and because these are the PACKAGE's layouts,
    | the host has no place to put that class. So the layout puts it there:
    |
    |   'auto'  (default) — mirror the reader's system preference, and keep mirroring it
    |                       when they change it. Emits a tiny inline head script so a
    |                       dark-mode reader never sees a white flash.
    |   'light'           — always light. No class, no inline script.
    |   'dark'            — always dark. The class is rendered server-side.
    |
    | Pinning the theme is also the escape hatch under a strict Content-Security-Policy:
    | only 'auto' emits an inline script at all. If you keep 'auto' under a strict CSP, give
    | that script a nonce your policy allows — a STATIC string in 'csp_nonce' below, or, for a
    | per-request nonce, register one from a service provider with
    | UiTheme::resolveNonceUsing(fn () => Vite::cspNonce()). Do NOT put a closure in 'csp_nonce':
    | a closure in config makes `php artisan config:cache` throw.
    |
    | The Tailwind utilities these screens are built from are compiled by YOUR app's
    | build — see the README's "Styling the UI" section for the two source globs it
    | needs. The package ships no compiled stylesheet. If your app has its own Vite
    | pipeline, point 'assets' at a Blade partial that emits it (e.g. @vite([...])) and
    | the package layouts @include it in <head> — so the shipped screens load YOUR
    | compiled CSS/JS without you having to publish and fork the layout.
    |
    */

    'ui' => [
        'theme' => env('WEBHOOKS_UI_THEME', 'auto'),

        // A Blade view rendered into the package layouts' <head> — your @vite tags (or any
        // <link>/<script>) so the shipped screens use your asset pipeline. Null renders nothing.
        'assets' => null,

        // Nonce for the inline theme script under a strict CSP: a STATIC string, or null (no
        // nonce — fine without a CSP). For a per-request nonce, register a resolver from a
        // service provider instead — UiTheme::resolveNonceUsing(fn () => Vite::cspNonce()) —
        // NOT a closure here: a closure in config makes `php artisan config:cache` throw.
        'csp_nonce' => null,
    ],

];
