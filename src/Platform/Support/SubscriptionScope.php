<?php

declare(strict_types=1);

namespace Webhooks\Platform\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Support\TenantIdentity;

/**
 * Resolves the tenant every self-service subscription query is scoped by, so a tenant
 * only ever sees and manages the endpoints it owns — a foreign owner's rows are never
 * visible. Because a subscription's owner is a morphTo, the tenant is identified by the
 * WHOLE (owner_type, owner_id) pair, never the id alone: two tenants that share an
 * owner_id under different owner types are different tenants.
 *
 * The default resolution reads the authenticated user: a host that groups endpoints
 * under a team keys them by the team (currentTeam), otherwise the user is its own
 * tenant. Either way the resolver yields the tenant MODEL, from which the morph pair is
 * derived — consistent with how the create path stores the owner. A host with a
 * different tenant model registers its own resolver with resolveUsing() so the package
 * stays agnostic about how a tenant is identified — and a test injects a fixed owner the
 * same way.
 */
final class SubscriptionScope
{
    /**
     * @var (Closure(): (TenantIdentity|Model|array<array-key, mixed>|null))|null
     */
    private static ?Closure $resolver = null;

    /**
     * Override how the current tenant is resolved. The closure yields the tenant's
     * morph identity: return the tenant Model (its morph class + key are derived), an
     * explicit TenantIdentity, a [type, id] pair, or null when no tenant is in scope.
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
     * The morph identity of the current tenant, or null when none is resolved.
     */
    public static function currentOwner(): ?TenantIdentity
    {
        return self::normalize((self::$resolver ?? self::defaultResolver())());
    }

    /**
     * The current tenant's owner id alone. Prefer currentOwner(), which carries the
     * owner_type too; a null tenant yields null. Kept for callers that only need the id.
     */
    public static function currentOwnerId(): int|string|null
    {
        return self::currentOwner()?->id;
    }

    /**
     * Constrain a subscription query to the current tenant's own endpoints, matching
     * BOTH owner columns. Foreign owners — and, deliberately, the global owner-less
     * subscriptions — are invisible to a self-service tenant, so a customer only manages
     * what it registered. With no tenant in scope the query is constrained to nothing.
     *
     * @param  Builder<WebhookSubscription>  $query
     * @return Builder<WebhookSubscription>
     */
    public static function scopeToCurrentOwner(Builder $query): Builder
    {
        $owner = self::currentOwner();

        if (! $owner instanceof TenantIdentity) {
            return $query->whereRaw('1 = 0');
        }

        return self::constrain($query, $owner);
    }

    /**
     * A fresh subscription query scoped to a single owner by BOTH morph columns.
     *
     * @return Builder<WebhookSubscription>
     */
    public static function forOwner(string $ownerType, int|string $ownerId): Builder
    {
        return self::constrain(WebhookSubscription::query(), new TenantIdentity($ownerType, $ownerId));
    }

    /**
     * @param  Builder<WebhookSubscription>  $query
     * @return Builder<WebhookSubscription>
     */
    private static function constrain(Builder $query, TenantIdentity $owner): Builder
    {
        return $query->where('owner_type', $owner->type)->where('owner_id', $owner->id);
    }

    /**
     * Normalise whatever a resolver yields into a tenant identity (or null).
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
            return self::fromArray($resolved);
        }

        throw new RuntimeException(
            'A webhook tenant resolver must yield a TenantIdentity, an Eloquent model, a '
            .'[type, id] pair or null. Register one with SubscriptionScope::resolveUsing().'
        );
    }

    /**
     * @param  array<array-key, mixed>  $pair
     */
    private static function fromArray(array $pair): TenantIdentity
    {
        $type = $pair['type'] ?? $pair[0] ?? null;
        $id = $pair['id'] ?? $pair[1] ?? null;

        if (is_string($type) && (is_int($id) || is_string($id))) {
            return new TenantIdentity($type, $id);
        }

        throw new RuntimeException(
            'A webhook tenant [type, id] pair must carry a string type and an int|string id.'
        );
    }

    /**
     * @return Closure(): Model
     */
    private static function defaultResolver(): Closure
    {
        return static function (): Model {
            $user = Auth::user();

            if (! $user instanceof Model) {
                throw new RuntimeException(
                    'Webhook self-service is tenant-scoped but no authenticated owner model was resolved. '
                    .'Register a resolver with SubscriptionScope::resolveUsing().'
                );
            }

            return self::currentTeam($user) ?? $user;
        };
    }

    /**
     * A Jetstream-style team when the user model exposes a currentTeam relation, read
     * defensively so a plain user model (no team concept) simply falls through to the
     * user itself as its own tenant.
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
