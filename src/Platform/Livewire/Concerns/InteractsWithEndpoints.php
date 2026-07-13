<?php

declare(strict_types=1);

namespace Webhooks\Platform\Livewire\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Webhooks\Core\Ssrf\SsrfGuard;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Platform\Support\SubscriptionScope;

/**
 * Shared plumbing for the self-service portal panels: build the owner-scoped
 * subscription query, load a single endpoint the acting tenant owns (a foreign id
 * never resolves), and read the self-service switches. Keeping it in one trait means
 * every panel scopes identically, so a tenant only ever reaches its own endpoints.
 *
 * The consuming component is a Livewire component, so $this->authorize() and
 * $this->dispatch() are available from the base class.
 *
 * @internal
 */
trait InteractsWithEndpoints
{
    /**
     * A subscription query constrained to the current tenant's own endpoints.
     *
     * @return Builder<WebhookSubscription>
     */
    protected function scopedQuery(): Builder
    {
        return SubscriptionScope::scopeToCurrentOwner(WebhookSubscription::query());
    }

    /**
     * Load one endpoint the acting tenant owns. The owner filter comes first, so a
     * cross-tenant id simply resolves to nothing and fails with a not-found before
     * any action runs — the row-level policy is the second, defence-in-depth guard.
     */
    protected function findOwnedEndpoint(int $id): WebhookSubscription
    {
        return $this->scopedQuery()->findOrFail($id);
    }

    /**
     * How many endpoints a single tenant may register, or null for unlimited.
     */
    protected function maxEndpointsPerTenant(): ?int
    {
        $max = Config::get('webhooks.platform.self_service.max_endpoints_per_tenant');

        return is_int($max) && $max >= 0 ? $max : null;
    }

    /**
     * Whether the tenant has reached its endpoint cap, so registering another is
     * refused. An unset cap is always false.
     */
    protected function endpointCapReached(): bool
    {
        $max = $this->maxEndpointsPerTenant();

        return $max !== null && $this->scopedQuery()->count() >= $max;
    }

    /**
     * How many seconds a freshly created or rotated secret stays revealable. A
     * non-positive configured value falls back to the built-in default.
     */
    protected function secretRevealTtl(): int
    {
        $ttl = Config::integer('webhooks.platform.self_service.secret_reveal_ttl', 60);

        return $ttl > 0 ? $ttl : 60;
    }

    /**
     * Whether endpoint deletion is permitted at all for this installation.
     */
    protected function deletionAllowed(): bool
    {
        return Config::boolean('webhooks.platform.self_service.allow_delete', true);
    }

    /**
     * The shared SSRF policy used to vet an endpoint URL before it is stored, so a
     * tenant cannot register an endpoint aimed at an internal address.
     */
    protected function ssrfGuard(): SsrfGuard
    {
        return Container::getInstance()->make(SsrfGuard::class);
    }
}
