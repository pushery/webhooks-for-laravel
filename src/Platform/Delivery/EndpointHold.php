<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Delivery;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Pushery\Webhooks\Support\Settings;

/**
 * The hold the Platform puts on an endpoint whose attempts keep failing, kept in the cache per endpoint.
 *
 * The breaker of `platform.circuit_breaker.threshold` counts exhausted deliveries, so a dead endpoint has every delivery
 * queued for it keep trying until that many of them have spent all their tries, and each attempt holds a worker for its
 * connect timeout while the endpoints of every other subscription wait. The hold counts attempts instead:
 * `hold.attempts` failed attempts within `hold.window` seconds hold the endpoint for `hold.seconds`, and its deliveries
 * wait in the queue without an attempt. Once the hold has run out, one delivery goes as the probe while the others wait
 * as long as an attempt can take: the probe's success lifts the hold, its failure holds the endpoint again. The probes
 * that fail in a row are counted, and the breaker switches the endpoint off once they come to its threshold: a held
 * delivery with a deadline is charged no time, so a dead endpoint under a deadline exhausts no delivery.
 *
 * Off while `hold.attempts` is null, and a delivery the Platform did not create, which names no subscription, is never
 * held. The counts live in the limiter store, as the dispatch schedule does, so every worker reads the same hold, and
 * they are kept per subscription and URL.
 *
 * @internal
 */
final readonly class EndpointHold
{
    /** What an attempt may take beyond its two timeouts before its probe counts as lost and another may go. */
    private const int PROBE_HEADROOM = 5;

    /** How long a hold is remembered past its end, so the probe it waits for is still owed a day later. */
    private const int REMEMBERED = 86400;

    public function __construct(private Settings $settings) {}

    /**
     * Count a failed attempt against its endpoint, and hold the endpoint once the attempts come to the threshold.
     *
     * @return int the probes of the endpoint that failed in a row, this one included, or 0 for an attempt that was no
     *             probe
     */
    public function failed(WebhookDeliveryData $data): int
    {
        $endpoint = $this->endpointOf($data);
        $threshold = $this->settings->holdAttempts();

        if ($endpoint === null || $threshold === null) {
            return 0;
        }

        // A failed probe holds the endpoint again at once: the attempts that held it were counted already.
        if ($this->store()->has($this->key($endpoint, 'probe'))) {
            $this->hold($endpoint);

            $probes = $this->key($endpoint, 'failed-probes');
            $this->store()->add($probes, 0, $this->settings->holdSeconds() + self::REMEMBERED);

            // A store that cannot count answers false, which reads as no failed probe.
            return (int) $this->store()->increment($probes);
        }

        $failures = $this->key($endpoint, 'failures');
        $this->store()->add($failures, 0, $this->settings->holdWindow());
        $count = $this->store()->increment($failures);

        if (is_numeric($count) && (int) $count >= $threshold) {
            $this->hold($endpoint);
        }

        return 0;
    }

    /**
     * Lift the hold of an endpoint an attempt got through to, and forget its failed attempts. Nothing to lift while the
     * hold is off, so a successful attempt then costs no cache call.
     */
    public function succeeded(WebhookDeliveryData $data): void
    {
        $endpoint = $this->endpointOf($data);

        if ($endpoint === null || $this->settings->holdAttempts() === null) {
            return;
        }

        foreach (['failures', 'until', 'probe', 'failed-probes'] as $part) {
            $this->store()->forget($this->key($endpoint, $part));
        }
    }

    /**
     * How long this delivery waits before it is tried, or null when it may go now: the rest of its endpoint's hold,
     * and once the hold has run out, as long as an attempt can take for every delivery but the one that goes as the
     * probe.
     */
    public function waitFor(WebhookDeliveryData $data): ?int
    {
        $endpoint = $this->endpointOf($data);

        if ($endpoint === null || $this->settings->holdAttempts() === null) {
            return null;
        }

        $until = $this->store()->get($this->key($endpoint, 'until'));

        if (! is_numeric($until)) {
            return null;
        }

        $left = (int) $until - Date::now()->getTimestamp();

        if ($left > 0) {
            return $left;
        }

        $attempt = $data->options->connectTimeout + $data->options->timeout + self::PROBE_HEADROOM;

        return $this->store()->add($this->key($endpoint, 'probe'), true, $attempt) ? null : $attempt;
    }

    private function hold(string $endpoint): void
    {
        $seconds = $this->settings->holdSeconds();

        $this->store()->put($this->key($endpoint, 'until'), Date::now()->getTimestamp() + $seconds, $seconds + self::REMEMBERED);
        $this->store()->forget($this->key($endpoint, 'failures'));
        $this->store()->forget($this->key($endpoint, 'probe'));
    }

    /**
     * The endpoint a delivery goes to, as its subscription and its URL, or null for a delivery the Platform did not
     * create. The URL is part of it because a database per tenant can give two tenants' subscriptions the same id, and
     * one tenant's dead endpoint must not hold the other's.
     */
    private function endpointOf(WebhookDeliveryData $data): ?string
    {
        $subscription = $data->meta['subscription_id'] ?? null;

        if (is_int($subscription) || is_string($subscription)) {
            return hash('sha256', $subscription."\n".$data->url);
        }

        return null;
    }

    private function key(string $endpoint, string $part): string
    {
        return "webhooks:hold:{$endpoint}:{$part}";
    }

    /** The limiter store, resolved the way Laravel's own RateLimiter resolves it. */
    private function store(): Repository
    {
        $store = Config::get('cache.limiter');

        return Cache::store(is_string($store) ? $store : null);
    }
}
