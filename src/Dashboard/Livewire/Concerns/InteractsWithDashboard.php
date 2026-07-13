<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Livewire\Concerns;

use Illuminate\Container\Container;
use RuntimeException;
use Webhooks\Dashboard\DashboardScope;
use Webhooks\Dashboard\Events\WebhookRedeliveryRequested;
use Webhooks\Dashboard\Metrics\WebhookMetrics;
use Webhooks\Dashboard\WindowResolver;
use Webhooks\Models\WebhookDelivery;

/**
 * Shared plumbing for the dashboard panels: build the tenant-scoped metrics query
 * for a window, resolve the configured read-surface model, load a delivery filtered
 * to the acting tenant, and run the authorized, event-driven replay. Keeping it in
 * one trait means every panel scopes and authorizes identically.
 *
 * The consuming component is a Livewire component, so $this->authorize() and
 * $this->dispatch() are available from the base class.
 *
 * @internal
 */
trait InteractsWithDashboard
{
    /**
     * The metrics query object for the acting tenant over the given window token.
     */
    protected function metricsFor(string $window): WebhookMetrics
    {
        return Container::getInstance()->make(WebhookMetrics::class, [
            'owner' => DashboardScope::currentOwner(),
            'window' => WindowResolver::interval($window),
        ]);
    }

    /**
     * Authorize and request a replay of one delivery, scoped to the acting tenant.
     * A delivery belonging to another tenant is never found — it 404s before the
     * policy runs — and the policy is the second guard on the action itself.
     *
     * A replay to a DISABLED endpoint is refused where the operator can see why: the
     * endpoint was switched off, by its tenant or by the circuit breaker, and replaying
     * into it would send data to somewhere that is meant to be receiving none.
     */
    public function redeliver(string $deliveryId): void
    {
        $delivery = $this->scopedDelivery($deliveryId);

        $this->authorize('redeliver', $delivery);

        if (! $delivery->subscription->is_active) {
            $this->dispatch('wirekit-toast', variant: 'warning', message: __('webhooks::dashboard.toast.endpoint_disabled'));

            return;
        }

        WebhookRedeliveryRequested::dispatch($delivery);

        $this->dispatch('wirekit-toast', variant: 'success', message: __('webhooks::dashboard.toast.redelivery_queued'));
    }

    /**
     * Load a single delivery for the acting tenant, or fail with a not-found — the
     * tenant filter comes first so a cross-tenant id can never be addressed.
     */
    protected function scopedDelivery(string $deliveryId): WebhookDelivery
    {
        $owner = DashboardScope::currentOwner();

        return $this->sourceModel()
            ->newQuery()
            ->where('owner_type', $owner->type)
            ->where('owner_id', $owner->id)
            ->findOrFail($deliveryId);
    }

    /**
     * A fresh instance of the configured read-surface model — the delivery log the
     * whole dashboard reads from.
     */
    protected function sourceModel(): WebhookDelivery
    {
        $class = config('webhooks.dashboard.source_model', WebhookDelivery::class);

        if (! is_string($class)) {
            throw new RuntimeException('The webhooks.dashboard.source_model must be a class-string.');
        }

        $model = Container::getInstance()->make($class);

        if (! $model instanceof WebhookDelivery) {
            throw new RuntimeException(
                "The configured webhooks.dashboard.source_model [{$class}] must be a "
                .WebhookDelivery::class.' or a subclass of it.'
            );
        }

        return $model;
    }
}
