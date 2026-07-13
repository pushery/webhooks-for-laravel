<?php

declare(strict_types=1);

namespace Webhooks\Platform\Health;

use Illuminate\Contracts\Events\Dispatcher;
use Webhooks\Events\WebhookDeliveryFailed;
use Webhooks\Events\WebhookDeliverySucceeded;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Support\Settings;

/**
 * Keeps an endpoint's cached health fresh by recomputing it whenever one of its
 * deliveries finishes. Wired to the terminal delivery events (a success, or a final
 * failure). Opt-in: while webhooks.platform.health.enabled is false the recompute is
 * skipped, so the cached columns only move when a host asks for continuous scoring;
 * the on-demand command and the score query itself always work regardless.
 *
 * @internal
 */
final readonly class RefreshEndpointHealthOnDelivery
{
    public function __construct(
        private EndpointHealth $health,
        private Settings $config,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(WebhookDeliverySucceeded::class, [self::class, 'onSucceeded']);
        $events->listen(WebhookDeliveryFailed::class, [self::class, 'onFailed']);
    }

    public function onSucceeded(WebhookDeliverySucceeded $event): void
    {
        $this->refresh($event->delivery->subscription);
    }

    public function onFailed(WebhookDeliveryFailed $event): void
    {
        $this->refresh($event->delivery->subscription);
    }

    private function refresh(WebhookSubscription $subscription): void
    {
        if (! $this->config->healthEnabled()) {
            return;
        }

        // Debounce: recomputing the full percentile window on every finished delivery
        // is redundant with the scheduled webhooks:refresh-endpoint-health command, so
        // a busy endpoint skips the recompute while its cached score is still fresh.
        if ($this->recentlyCalculated($subscription)) {
            return;
        }

        $this->health->refresh($subscription);
    }

    /**
     * Whether the endpoint's cached health score was recomputed within the configured
     * minimum interval. A never-scored endpoint (or a zero interval) is never fresh, so
     * the first finished delivery always scores it.
     */
    private function recentlyCalculated(WebhookSubscription $subscription): bool
    {
        $interval = $this->config->healthRefreshMinIntervalSeconds();
        $calculatedAt = $subscription->health_calculated_at;

        if ($interval <= 0 || $calculatedAt === null) {
            return false;
        }

        return $calculatedAt->greaterThan(now()->subSeconds($interval));
    }
}
