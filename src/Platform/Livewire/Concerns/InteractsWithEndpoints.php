<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Support\EndpointRegistration;
use Pushery\Webhooks\Platform\Support\PortalRefusal;
use Pushery\Webhooks\Platform\Support\SelfServiceEndpoints;
use Pushery\Webhooks\Platform\Support\SubscriptionScope;
use Pushery\Webhooks\Support\PerMinuteBrake;
use Pushery\Webhooks\Support\ReplayAllowance;
use Pushery\Webhooks\Support\TenantIdentity;

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
     * Re-authorize the portal gate on every request, not just the first one.
     *
     * Livewire runs mount() only on the initial request; every later interaction is a
     * /livewire/update request that skips it. A gate authorized in mount() alone is therefore
     * replayable — revoke a tenant's ability mid-session and the panel keeps serving until the
     * reader reloads. The dashboard is spared this because its route carries the gate as
     * middleware and Livewire re-applies `can:` on update (persistent middleware); the portal's
     * documented middleware is only ['web', 'auth'], so its panels assert the gate themselves.
     *
     * boot() is the first hook on both the mount and the hydrate path, so the ability is checked
     * before mount() loads anything and before any action runs. It refuses identically whichever
     * request hits it — through {@see PortalRefusal}, so a host that answers 404 everywhere gets
     * the same answer here as from its own screens, without the gate itself changing.
     * Row-level ownership stays a separate, second guard (a foreign id fails not-found first).
     *
     * boot() does not run on every request. A `#[Lazy]` panel is mounted as a placeholder first,
     * and Livewire skips the hydrate of that placeholder snapshot, boot() included, on the
     * request that follows. That request is meant to be the `__lazyLoad` that mounts the panel
     * for real, and nothing makes it so: a `$refresh` or a `gotoPage()` sent against an unloaded
     * placeholder of EndpointList, the one lazy panel here, would render the tenant's list after
     * the ability had been revoked. So the gate runs again before every render (the `rendering`
     * hook below), which no placeholder skips, and the render is where a tenant's rows leave the
     * server.
     *
     * An action on that path still runs without the gate, and that is why the row-level
     * `authorize('view'|'update'|'rotateSecret', $subscription)` calls stay. In the five panels
     * that are not lazy they are a second line: the gate above refuses first, and the policy adds
     * only `ownedByCurrentTenant()`, which findOwnedEndpoint() has already enforced —
     * scopeToCurrentOwner() answers `1 = 0` for a null tenant, so a row that loaded at all is a
     * row the tenant owns. In EndpointList they are the only refusal on the placeholder path, and
     * there two of the four carry weight: without `toggle`'s or `ping`'s, an action sent to an
     * unloaded placeholder switches or pings the endpoint before the render gate refuses. `edit`
     * and `reveal` only dispatch an event, and a refused render delivers none. Do not delete them.
     *
     * `create` is the one that is genuinely reachable, and it is reachable for a structural
     * reason: there is no row yet, so the scoping cannot speak, and the policy's
     * `currentOwner() instanceof TenantIdentity` is the only tenant check between an
     * ability-holding reader with no tenant in scope and an ownerless endpoint that receives
     * every tenant's payloads. There are three call sites: the two branches in EndpointForm, and
     * EndpointList::newEndpoint().
     */
    public function bootInteractsWithEndpoints(): void
    {
        PortalRefusal::shape(fn () => $this->authorize('manage-webhook-endpoints'));
    }

    /**
     * The same gate before every render, because boot() does not run on the request that follows
     * a lazy placeholder (see above). Variadic because Livewire hands trait hooks their context as
     * named arguments, and this check needs none of it.
     */
    public function renderingInteractsWithEndpoints(mixed ...$context): void
    {
        PortalRefusal::shape(fn () => $this->authorize('manage-webhook-endpoints'));
    }

    /**
     * A subscription query constrained to the current tenant's own endpoints.
     *
     * @return Builder<WebhookSubscription>
     */
    protected function scopedQuery(): Builder
    {
        return SubscriptionScope::scopeToCurrentOwner(WebhookSubscription::model()::query());
    }

    /**
     * Load one endpoint the acting tenant owns. The owner filter comes first, so a
     * cross-tenant id simply resolves to nothing and fails with a not-found before
     * any action runs — the row-level policy is the second, defense-in-depth guard.
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
        return EndpointRegistration::maxEndpoints();
    }

    /**
     * The sentence a registration of the tenant in scope is refused with right now, or null when it
     * may register one more endpoint: {@see EndpointRegistration::refusal()}, the same answer a
     * registration through {@see SelfServiceEndpoints} gets.
     *
     * Read on its own this is only ever advisory — it is what decides whether a button is drawn.
     * With no tenant in scope the scoped query counts nothing, so only a cap of zero refuses.
     */
    protected function registrationRefusal(string $portalSentence): ?string
    {
        $owner = SubscriptionScope::currentOwner();

        if ($owner instanceof TenantIdentity) {
            return EndpointRegistration::refusal($owner, $portalSentence);
        }

        if ($this->maxEndpointsPerTenant() === 0) {
            return $portalSentence;
        }

        return null;
    }

    /**
     * How many endpoints one tenant may register per minute, or null for no brake
     * ({@see EndpointRegistration::maxPerMinute()}).
     */
    protected function maxRegistrationsPerMinute(): ?int
    {
        return EndpointRegistration::maxPerMinute();
    }

    /**
     * Whether the tenant in scope has spent its registration allowance for the current minute,
     * spending one when it has not ({@see EndpointRegistration::rateExceeded()}). With no tenant in
     * scope there is no bucket to spend from, and nothing is refused.
     */
    protected function registrationRateExceeded(): bool
    {
        $owner = SubscriptionScope::currentOwner();

        return $owner instanceof TenantIdentity && EndpointRegistration::rateExceeded($owner);
    }

    /**
     * The window the recompute allowances are measured over, in seconds.
     */
    private const int RECOMPUTE_RATE_WINDOW = 60;

    /**
     * How many deliveries one tenant may replay per minute, or null for no brake.
     *
     * The allowance itself, with its reasons, is {@see ReplayAllowance}: the per-tenant dashboard
     * spends the same budget, so the portal reads it from there rather than keeping a copy.
     */
    protected function maxReplaysPerMinute(): ?int
    {
        return ReplayAllowance::perMinute();
    }

    /**
     * Whether this tenant has spent its replay allowance for the current minute.
     *
     * Spent only by attempts that reach the send path: an unauthorized or not-found replay
     * costs nothing against it, so a probe cannot burn a real tenant's allowance.
     */
    protected function replayRateExceeded(): bool
    {
        $owner = SubscriptionScope::currentOwner();

        if (! $owner instanceof TenantIdentity) {
            return false;
        }

        return ! ReplayAllowance::spend($owner);
    }

    /**
     * How many full health recomputes one tenant may run per minute, or null for no brake. The
     * same number bounds single-row recomputes, on an allowance of their own.
     *
     * The most expensive action the portal offers and, until 2026-08-27, the only one with no
     * brake at all — while registration, replay and the test ping each had one. Every other
     * tenant action costs a bounded amount of work; this one costs two queries per endpoint,
     * synchronously, in the web request, and `max_endpoints_per_tenant` ships as null, so the
     * multiplier has no ceiling either.
     *
     * The default is low on purpose. Registration and replay allow ten a minute because ten
     * registrations is ten rows; two recomputes is 2·2·N queries, and the scheduled refresh
     * already does the same work in the background — pressing the button repeatedly buys the
     * reader nothing the next tick would not give them.
     *
     * A non-positive value reads as no brake rather than as "none allowed", the same way the
     * two beside it do: a limit of zero would refuse every recompute, which is a way to
     * disable a feature by typo rather than a setting anyone wants.
     */
    protected function maxRecomputesPerMinute(): ?int
    {
        return PerMinuteBrake::read(Config::get('webhooks.platform.self_service.recomputes_per_minute', 2), 2);
    }

    /**
     * Whether this tenant has spent its recompute allowance for the current minute.
     */
    protected function recomputeRateExceeded(): bool
    {
        $max = $this->maxRecomputesPerMinute();
        $owner = SubscriptionScope::currentOwner();

        if ($max === null || ! $owner instanceof TenantIdentity) {
            return false;
        }

        return PerMinuteBrake::spend([$this->recomputeRateKey($owner)], $max, self::RECOMPUTE_RATE_WINDOW) !== null;
    }

    /**
     * Whether this tenant has spent its allowance for recomputing single rows this minute.
     *
     * The same limit as the full pass, on a bucket of its own. One row costs two queries where
     * the pass costs two per endpoint, but the row action pressed in a loop adds up to the
     * same pass one request at a time.
     */
    protected function rowRecomputeRateExceeded(): bool
    {
        $max = $this->maxRecomputesPerMinute();
        $owner = SubscriptionScope::currentOwner();

        if ($max === null || ! $owner instanceof TenantIdentity) {
            return false;
        }

        return PerMinuteBrake::spend([$this->rowRecomputeRateKey($owner)], $max, self::RECOMPUTE_RATE_WINDOW) !== null;
    }

    /**
     * The recompute allowance's cache key for one tenant. A fourth key beside the three
     * below, and its own for the reason the third one is: sharing a bucket would make one
     * action exhaust another, and neither reader would have any way to see why.
     */
    protected function recomputeRateKey(TenantIdentity $owner): string
    {
        return 'webhooks:endpoint-health-recompute-rate:'.str_replace('\\', '.', $owner->type).':'.$owner->id;
    }

    /**
     * The single-row recompute allowance's cache key for one tenant, apart from the full
     * pass's for the same reason: a few row presses must not refuse the pass.
     */
    protected function rowRecomputeRateKey(TenantIdentity $owner): string
    {
        return 'webhooks:endpoint-health-row-recompute-rate:'.str_replace('\\', '.', $owner->type).':'.$owner->id;
    }

    /**
     * The replay allowance's cache key for one tenant. A third key beside the two below, and
     * deliberately its own: sharing one with the registration allowance would make a tenant
     * that registered an endpoint unable to replay a delivery, and neither reader would have
     * any way to see why.
     *
     * Named rather than inlined, so all three keys are built the same way.
     */
    protected function replayRateKey(TenantIdentity $owner): string
    {
        return ReplayAllowance::key($owner);
    }

    /**
     * The registration allowance's cache key for one tenant ({@see EndpointRegistration::rateKey()}).
     */
    protected function registrationRateKey(TenantIdentity $owner): string
    {
        return EndpointRegistration::rateKey($owner);
    }

    /**
     * The registration lock's cache key for one tenant ({@see EndpointRegistration::lockKey()}).
     */
    protected function registrationLockKey(TenantIdentity $owner): string
    {
        return EndpointRegistration::lockKey($owner);
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
}
