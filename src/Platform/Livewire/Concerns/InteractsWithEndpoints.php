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
     * Re-authorize the portal gate on EVERY request, not just the first one.
     *
     * Livewire runs mount() only on the initial request; every later interaction is a
     * /livewire/update request that skips it. A gate authorized in mount() alone is therefore
     * replayable — revoke a tenant's ability mid-session and the panel keeps serving until the
     * reader reloads. The dashboard is spared this because its route carries the gate as
     * middleware and Livewire re-applies `can:` on update (persistent middleware); the portal's
     * documented middleware is only ['web', 'auth'], so its panels assert the gate themselves.
     *
     * boot() is the first hook on BOTH the mount and the hydrate path, so the ability is checked
     * before mount() loads anything and before any action runs. Failing it throws the same 403 an
     * unauthorized mount always has — the gate answers identically whichever request hits it.
     * Row-level ownership stays a separate, second guard (a foreign id fails not-found first).
     */
    public function bootInteractsWithEndpoints(): void
    {
        $this->authorize('manage-webhook-endpoints');
    }

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
