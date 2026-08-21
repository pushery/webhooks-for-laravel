<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Livewire\Concerns;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Pushery\Webhooks\Core\Ssrf\SsrfGuard;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Support\PortalRefusal;
use Pushery\Webhooks\Platform\Support\SubscriptionScope;
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
     * before mount() loads anything and before any action runs. It refuses identically whichever
     * request hits it — through {@see PortalRefusal}, so a host that answers 404 everywhere gets
     * the same answer here as from its own screens, without the gate itself changing.
     * Row-level ownership stays a separate, second guard (a foreign id fails not-found first).
     *
     * ⚠️ THAT SECOND GUARD IS UNREACHABLE AS THE *SOLE* REFUSAL, and knowing why saves the next
     * reader a wasted afternoon. Mutation testing reports every `authorize('view'|'update'|
     * 'rotateSecret', $subscription)` in these panels as a survivor — measured 2026-08-20:
     * comment any of the five out and the whole 236-test portal suite stays green. They are not
     * untested, they are unreachable: this boot gate reads the SAME `manage-webhook-endpoints`
     * ability the policy consults, and the only condition the policy adds on top is
     * `ownedByCurrentTenant()`, which findOwnedEndpoint() has already enforced —
     * scopeToCurrentOwner() answers `1 = 0` for a null tenant, so a row that loaded at all is a
     * row the tenant owns. Do not "kill" them by deleting them: they are what still refuses if
     * a future caller reaches an action without the scoped query.
     *
     * `create` is the ONE that is genuinely reachable, and it is reachable for a structural
     * reason: there is no row yet, so the scoping cannot speak, and the policy's
     * `currentOwner() instanceof TenantIdentity` is the only tenant check between an
     * ability-holding reader with no tenant in scope and an OWNERLESS endpoint that receives
     * every tenant's payloads. All three call sites are pinned (EndpointForm's two arms, and
     * EndpointList::newEndpoint()).
     */
    public function bootInteractsWithEndpoints(): void
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
        return SubscriptionScope::scopeToCurrentOwner(WebhookSubscription::query());
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
        $max = Config::get('webhooks.platform.self_service.max_endpoints_per_tenant');

        if (is_int($max) && $max >= 0) {
            return $max;
        }

        return null;
    }

    /**
     * Whether the tenant has reached its endpoint cap, so registering another is
     * refused. An unset cap is always false.
     *
     * Read on its own this is only ever advisory — it is what decides whether a button is
     * drawn. The decision that MUST hold is the one inside {@see withRegistrationLock()},
     * which asks the same question with the answer pinned.
     */
    protected function endpointCapReached(): bool
    {
        $max = $this->maxEndpointsPerTenant();

        return $max !== null && $this->scopedQuery()->count() >= $max;
    }

    /**
     * How long the registration lock is held before it expires on its own, in seconds.
     *
     * A backstop, not a budget: the section it guards is one count and one insert. It
     * exists so a request killed mid-registration cannot wedge a tenant out of registering
     * until the cache is flushed.
     */
    private const int REGISTRATION_LOCK_TTL = 10;

    /**
     * How long a registration waits for a concurrent one to finish, in seconds.
     *
     * Waiting is the right answer rather than refusing outright: the other request
     * finishes in milliseconds, and the waiter then re-reads the cap and gets a correct
     * verdict — created, or honestly at the limit. Only a wait past this is reported as
     * contention.
     */
    private const int REGISTRATION_LOCK_WAIT = 3;

    /**
     * Run a registration inside the acting tenant's registration lock, so the cap check
     * and the insert cannot interleave with a concurrent registration.
     *
     * Without it the cap is read-then-act: two requests both read count = max - 1, both
     * pass, both insert, and the cap is over with nothing to notice. That is not a
     * contrived race — the cap bounds a SELF-SERVICE resource, and a double-submit is the
     * ordinary way one customer produces two simultaneous registrations.
     *
     * Keyed by the WHOLE morph pair, matching the query scope exactly, so two tenants
     * never wait on each other and two tenants sharing an owner_id under different owner
     * types are still separate. With no cap configured there is nothing to race for and no
     * lock is taken, so an unlimited installation pays nothing for this.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $register
     * @return TValue
     *
     * @throws LockTimeoutException when a concurrent registration held the lock too long
     */
    protected function withRegistrationLock(Closure $register): mixed
    {
        $owner = SubscriptionScope::currentOwner();

        if ($this->maxEndpointsPerTenant() === null || ! $owner instanceof TenantIdentity) {
            return $register();
        }

        return Cache::lock($this->registrationLockKey($owner), self::REGISTRATION_LOCK_TTL)
            ->block(self::REGISTRATION_LOCK_WAIT, $register);
    }

    /**
     * How many endpoints one tenant may register per minute, or null for no brake.
     *
     * A non-positive value reads as no brake rather than as "none allowed": a limit of
     * zero would refuse every registration, which is a way to disable the portal by typo
     * rather than a setting anyone wants.
     *
     * The shipped default is repeated here for the same reason as the test-ping brake: an
     * absent key reads as null and switches the brake off, and a host on a config cache
     * built before this version upgraded still has the old trimmed layer until it rebuilds.
     * ConfigDefaultsAreInSyncTest holds the two numbers together.
     */
    protected function maxRegistrationsPerMinute(): ?int
    {
        $max = Config::get('webhooks.platform.self_service.registrations_per_minute', 10);

        if (is_int($max) && $max > 0) {
            return $max;
        }

        return null;
    }

    /**
     * Whether this tenant has spent its registration allowance for the current minute.
     *
     * The cap and this answer different questions: the cap bounds how MANY endpoints a
     * tenant ends up with, this bounds how FAST it gets there. An installation with no cap
     * has no answer to a client that registers in a loop, and one with a cap still has
     * none to a client that empties and refills it.
     *
     * The bucket is spent by attempts that reach the write path — validation and
     * authorization have already run, so a rejected form costs nothing against it.
     */
    protected function registrationRateExceeded(): bool
    {
        $max = $this->maxRegistrationsPerMinute();
        $owner = SubscriptionScope::currentOwner();

        if ($max === null || ! $owner instanceof TenantIdentity) {
            return false;
        }

        $key = $this->registrationRateKey($owner);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return true;
        }

        RateLimiter::hit($key, self::REGISTRATION_RATE_WINDOW);

        return false;
    }

    /**
     * The window the registration allowance is measured over, in seconds.
     */
    private const int REGISTRATION_RATE_WINDOW = 60;

    /**
     * The registration allowance's cache key for one tenant. Distinct from the
     * registration LOCK's key: one bounds how fast a tenant may register, the other keeps
     * a single registration atomic, and sharing a key would make each break the other.
     */
    protected function registrationRateKey(TenantIdentity $owner): string
    {
        return 'webhooks:endpoint-registration-rate:'.str_replace('\\', '.', $owner->type).':'.$owner->id;
    }

    /**
     * The registration lock's cache key for one tenant. The morph class is dotted rather
     * than hashed so the key stays legible in a cache browser; both halves are present, so
     * it identifies exactly the tenant the scoped query does.
     */
    protected function registrationLockKey(TenantIdentity $owner): string
    {
        return 'webhooks:endpoint-registration:'.str_replace('\\', '.', $owner->type).':'.$owner->id;
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
