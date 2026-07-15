<?php

declare(strict_types=1);

namespace Webhooks\Dashboard;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Webhooks\Support\TenantIdentity;

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
     * What the current dashboard request is scoped to. In OPERATOR mode
     * (`webhooks.dashboard.operator = true`) the dashboard reads the global, owner-less rows
     * — the endpoints an operator registers with a null owner, which a tenant scope can never
     * see — and no tenant is resolved. Otherwise it is the acting tenant, resolved exactly as
     * {@see self::currentOwner()}.
     *
     * Operator mode shows global rows to whoever the `view-webhook-dashboard` ability lets in,
     * so gate that ability to operators — it is the same fail-closed gate the tenant dashboard
     * relies on, not a new one.
     */
    public static function current(): DashboardTenant
    {
        if (Config::boolean('webhooks.dashboard.operator', false)) {
            return DashboardTenant::global();
        }

        return DashboardTenant::forTenant(self::currentOwner());
    }

    /**
     * The morph identity of the tenant the current request is scoped to — the pair every
     * per-row delivery read and authorization matches against.
     */
    public static function currentOwner(): TenantIdentity
    {
        $identity = self::normalize(self::resolve());

        if (! $identity instanceof TenantIdentity) {
            throw new RuntimeException(
                'The webhook dashboard is tenant-scoped but no owner identity was resolved. '
                .'Register a resolver with DashboardScope::resolveUsing().'
            );
        }

        return $identity;
    }

    private static function resolve(): mixed
    {
        return (self::$resolver ?? self::defaultResolver())();
    }

    /**
     * Normalise a resolved value into a tenant identity, or null when it carries no
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

        if (! is_int($identifier) && ! (is_string($identifier) && is_numeric($identifier))) {
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

        return $team instanceof Model ? $team : null;
    }
}
