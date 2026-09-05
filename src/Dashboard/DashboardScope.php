<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Pushery\Webhooks\Support\TenantIdentity;
use RuntimeException;

/**
 * Resolves the tenant every dashboard query is scoped by. A customer-facing dashboard
 * is multi-tenant, so no delivery is ever read without an owner in scope. Because a
 * delivery carries the denormalized (owner_type, owner_id) of its subscription's owner,
 * per-row reads and authorization match the WHOLE morph pair — the id alone is not a
 * tenant, since two tenants can share an owner_id under different owner types.
 *
 * The default resolution reads the authenticated user and prefers a Jetstream-style
 * current team when the tenant model exposes one — the SAME rule the self-service
 * SubscriptionScope applies — so the dashboard and the portal scope to the identical
 * tenant. A host app that keys deliveries by a different tenant (workspace, account)
 * registers its own resolver with resolveUsing() so the package stays agnostic about
 * the tenant model.
 */
final class DashboardScope
{
    /**
     * @var (Closure(): (TenantIdentity|Model|array<array-key, mixed>|null))|null
     */
    private static ?Closure $resolver = null;

    /**
     * Override how the current tenant is resolved — the host app's tenant. The closure
     * yields the tenant's morph identity: the tenant Model (its morph class + key are
     * derived), an explicit TenantIdentity, a [type, id] pair, or null when no tenant is
     * in scope. A bare id is deliberately not accepted: it cannot identify a morph pair.
     *
     * @param  Closure(): (TenantIdentity|Model|array<array-key, mixed>|null)  $resolver
     */
    public static function resolveUsing(Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Drop a registered resolver and fall back to the default (auth-based) one.
     */
    public static function forget(): void
    {
        self::$resolver = null;
    }

    /**
     * What the current dashboard request is scoped to, in precedence order:
     *
     * 1. **ALL-TENANTS** (`webhooks.dashboard.all_tenants = true`) — every delivery, whoever
     *    owns it: the support/operator console. Checked first because it is the strictly wider
     *    scope, so a host that switches it on does not have to also remember to switch
     *    `operator` off. Additionally gated by its own ability, fail-closed (see below).
     * 2. **OPERATOR** (`webhooks.dashboard.operator = true`) — the global, owner-less rows
     *    only: the endpoints an operator registers with a null owner, which a tenant scope can
     *    never see. Not "everything"; a tenant's private rows stay invisible.
     * 3. Otherwise the acting tenant, resolved exactly as {@see self::currentOwner()}.
     *
     * Both operator modes show their rows to whoever the `view-webhook-dashboard` ability lets
     * in, so gate that ability to operators. All-tenants carries a SECOND gate on top, because
     * reading another tenant's delivery history is a higher permission level than reading your
     * own global endpoints.
     */
    public static function current(): DashboardTenant
    {
        if (Config::boolean('webhooks.dashboard.all_tenants', false)) {
            self::authorizeAllTenants();

            return DashboardTenant::allTenants();
        }

        if (Config::boolean('webhooks.dashboard.operator', false)) {
            return DashboardTenant::global();
        }

        // Resolved here rather than through currentOwner(), and the difference is a 500.
        //
        // currentOwner() promises a TenantIdentity, so it has to throw when there is none. But
        // "no tenant" is a state the `view-webhook-dashboard` ability ADMITS: an operator on a
        // fresh installation before the first tenant exists, a support account with no company
        // of its own, and every test that asserts the empty state. A state the ability lets a
        // person into and the page cannot survive is a defect, not a configuration question.
        //
        // Reported from a consumer against v2.6.0, where the standalone dashboard answered 500.
        // Under v2.3.0 the same state simply rendered nothing; the untenanted scope is that
        // behavior named rather than a new one.
        $identity = self::normalize(self::resolve());

        return $identity instanceof TenantIdentity
            ? DashboardTenant::forTenant($identity)
            : DashboardTenant::untenanted();
    }

    /**
     * Fail closed on the cross-tenant scope: the configured ability must EXIST and the acting
     * user must pass it. An undefined ability, a blank ability name and an unauthenticated
     * request all deny.
     *
     * The undefined case is the one worth spelling out, because the obvious default is the
     * dangerous one. Operator mode leaves an undefined ability open and that is fine there — it
     * exposes only the owner-less rows the operator itself registered. This scope exposes every
     * TENANT's delivery history, and it sits behind `view-webhook-dashboard`, an ability a
     * per-tenant dashboard necessarily grants BROADLY: every customer needs it to see their own
     * deliveries. So a host that flips this flag without defining a second ability would hand
     * every customer every other customer's history — silently, with nothing failing. Copying
     * operator mode's default here copies its shape without its safety.
     *
     * A host that genuinely wants no second gate says so explicitly —
     * `Gate::define('view-all-tenant-webhooks', fn () => true)` — which is greppable and
     * reviewable, unlike an absence.
     *
     * It DENIES rather than narrowing. Falling back to the tenant or global scope would leave a
     * support screen rendering successfully while showing a fraction of the rows it is supposed
     * to — no error, no empty state, nothing failing a test. That silent narrowing is the exact
     * failure this whole scope was added to prevent, so it must not be its own failure mode.
     */
    private static function authorizeAllTenants(): void
    {
        $ability = Config::string('webhooks.dashboard.all_tenants_ability', 'view-all-tenant-webhooks');

        // The `=== ''` half is EQUIVALENT and reported every run: `Gate::has('')` is false, so
        // the second half refuses an empty ability on its own. Kept because it names the case —
        // a host that blanked the config rather than a host that forgot to define the gate —
        // and the two are fixed differently.
        if ($ability === '' || ! Gate::has($ability)) {
            throw new AuthorizationException(
                'The webhook dashboard is in cross-tenant mode, which reads EVERY tenant\'s '
                .'deliveries, so it requires its own ability. Define '
                ."'".($ability === '' ? 'view-all-tenant-webhooks' : $ability)."' and grant it "
                .'to your operators — or set webhooks.dashboard.all_tenants to false.'
            );
        }

        $user = Auth::user();

        if ($user === null || Gate::forUser($user)->denies($ability)) {
            throw new AuthorizationException(
                'The webhook dashboard is in cross-tenant mode, which requires the '
                ."'{$ability}' ability."
            );
        }
    }

    /**
     * The morph identity of the tenant the current request is scoped to — the pair every
     * per-row delivery read and authorization matches against.
     */
    public static function currentOwner(): TenantIdentity
    {
        $identity = self::normalize(self::resolve());

        if (! $identity instanceof TenantIdentity) {
            // Two cases, two sentences, and merging them cost a reader an afternoon. The old
            // message said "Register a resolver with DashboardScope::resolveUsing()" for both --
            // and in the reported case a resolver WAS registered, it simply had nothing to
            // return. That sends the reader to the one place the problem is not.
            //
            // They are also repaired differently: one is a wiring step the host never took, the
            // other is a person who belongs to no tenant, which is an ordinary runtime state.
            // One sentence, not two, and the second draft is why.
            //
            // The old message said "Register a resolver with DashboardScope::resolveUsing()" --
            // and in the reported case a resolver WAS registered, it simply had nothing to
            // return, so the reader was sent to the one place the problem was not.
            //
            // The obvious repair was to split it in two. That was wrong twice over. Written as
            // a ternary it took the coverage floor from 100 to 94.6, because pcov credits a
            // multi-line ternary line by line and the arm that does not run reads as uncovered.
            // Written as two statements it stayed uncovered, because the unregistered case
            // CANNOT reach here: the default resolver throws its own message first, or returns
            // something normalize() can read. The arm asserting it passed only because that
            // other message happens to contain the same sentence.
            //
            // So the unregistered case keeps its wording where it already lives, and this one
            // says what is true when it is reached.
            throw new RuntimeException(
                'The webhook dashboard is tenant-scoped and its resolver returned no owner '
                .'identity. That is an ordinary state for a reader who belongs to no tenant '
                .'-- DashboardScope::current() answers it with an empty scope. Call that '
                .'instead, or have the resolver return a tenant.'
            );
        }

        return $identity;
    }

    private static function resolve(): mixed
    {
        return (self::$resolver ?? self::defaultResolver())();
    }

    /**
     * Normalize a resolved value into a tenant identity, or null when it carries no
     * owner type (a bare id cannot identify a morph pair on its own).
     */
    private static function normalize(mixed $resolved): ?TenantIdentity
    {
        if ($resolved === null || $resolved instanceof TenantIdentity) {
            return $resolved;
        }

        if ($resolved instanceof Model) {
            return TenantIdentity::fromModel($resolved);
        }

        if (is_array($resolved)) {
            $type = $resolved['type'] ?? $resolved[0] ?? null;
            $id = $resolved['id'] ?? $resolved[1] ?? null;

            if (is_string($type) && (is_int($id) || is_string($id))) {
                return new TenantIdentity($type, $id);
            }
        }

        throw new RuntimeException(
            'A webhook dashboard tenant resolver must yield a TenantIdentity, an Eloquent model '
            .'or a [type, id] pair to scope per-row reads. Register one with DashboardScope::resolveUsing().'
        );
    }

    /**
     * @return Closure(): (TenantIdentity|Model)
     */
    private static function defaultResolver(): Closure
    {
        return static function (): TenantIdentity|Model {
            $user = Auth::user();

            if ($user === null) {
                throw new RuntimeException(
                    'The webhook dashboard is tenant-scoped but no authenticated owner was resolved. '
                    .'Register a resolver with DashboardScope::resolveUsing().'
                );
            }

            return self::tenantFor($user);
        };
    }

    /**
     * The tenant for an authenticated user. An Eloquent tenant resolves exactly like the
     * self-service SubscriptionScope — a Jetstream-style current team when present,
     * otherwise the user itself — so the dashboard and the portal scope to the SAME
     * tenant; normalize() then derives the morph pair from the model's morph class + key,
     * matching how the owner is stored. A non-Eloquent authenticatable (e.g. GenericUser)
     * carries no morph class or team relation, so it falls back to its concrete class name
     * as a best-effort owner type and its numeric auth identifier as the owner id.
     */
    private static function tenantFor(Authenticatable $user): TenantIdentity|Model
    {
        if ($user instanceof Model) {
            return self::currentTeam($user) ?? $user;
        }

        $identifier = $user->getAuthIdentifier();

        if (! is_int($identifier) && (! is_string($identifier) || ! is_numeric($identifier))) {
            throw new RuntimeException(
                'The authenticated identifier is not a numeric owner id. Register a tenant-aware '
                .'resolver with DashboardScope::resolveUsing().'
            );
        }

        return new TenantIdentity($user::class, is_int($identifier) ? $identifier : (int) $identifier);
    }

    /**
     * A Jetstream-style team when the user model exposes a currentTeam relation, read
     * defensively so a plain user model (no team concept) simply falls through to the
     * user itself as its own tenant — mirrors SubscriptionScope so both agree.
     */
    private static function currentTeam(Model $user): ?Model
    {
        if (! method_exists($user, 'currentTeam')) {
            return null;
        }

        $team = $user->getAttribute('currentTeam');

        if ($team instanceof Model) {
            return $team;
        }

        return null;
    }
}
