<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard;

use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Database\OwnerKeyType;
use Pushery\Webhooks\Support\TenantIdentity;

/**
 * What a dashboard read is scoped to. Three scopes, and the distinction between the last
 * two is a privilege boundary, not a detail:
 *
 * - **tenant** — a single tenant, matched on the WHOLE owner morph pair.
 * - **global** — the owner-less rows only (`owner_type IS NULL AND owner_id IS NULL`): the
 *   endpoints an operator registers with a null owner, which no tenant scope can see. It is
 *   NOT "everything"; a tenant's private rows stay invisible.
 * - **all tenants** — every row, owner-less and tenant-owned alike. This is the support /
 *   operator console view ("what did we send to THIS customer's endpoint?"), and seeing
 *   another tenant's data is a real permission level above seeing the global ones — so it
 *   has its own config flag and its own ability, and is never implied by operator mode.
 *
 * The scope is expressed once, as a SQL condition + bindings, so every reader applies it
 * identically whether it is an Eloquent query, a query-builder read or one of the metrics'
 * hand-written percentile queries — a null-owner scope is `IS NULL`, which no `= ?` binding
 * can express, which is exactly why this is a condition and not a pair of values.
 *
 * @internal
 */
final readonly class DashboardTenant
{
    /**
     * A null identity alone no longer identifies the scope — global and all-tenants are both
     * owner-less at construction and mean opposite things — so the kind is carried explicitly.
     * The constructor is private and the three factories below are the only way in, so an
     * inconsistent pair cannot be built.
     */
    private function __construct(
        private ?TenantIdentity $identity,
        private DashboardScopeKind $kind,
    ) {}

    public static function forTenant(TenantIdentity $identity): self
    {
        return new self($identity, DashboardScopeKind::Tenant);
    }

    /**
     * The operator scope: the global, owner-less rows only. Never all tenants' rows — an
     * operator observes the endpoints it owns globally, not another tenant's private ones.
     * For a genuinely cross-tenant console, see {@see self::allTenants()}.
     */
    public static function global(): self
    {
        return new self(null, DashboardScopeKind::Global);
    }

    /**
     * The cross-tenant operator scope: EVERY row, whoever owns it. Reserved for a support or
     * operator console, behind its own flag and ability — the one scope that can read another
     * tenant's delivery history.
     */
    public static function allTenants(): self
    {
        return new self(null, DashboardScopeKind::AllTenants);
    }

    /**
     * The owner-less-only scope. Deliberately FALSE for all-tenants: that scope has no owner
     * identity either, so a `! $identity instanceof TenantIdentity` test would call it global
     * and quietly hand a caller the wrong answer about what it can see.
     */
    public function isGlobal(): bool
    {
        return $this->kind === DashboardScopeKind::Global;
    }

    /**
     * Whether this scope reads across tenant boundaries.
     */
    public function coversAllTenants(): bool
    {
        return $this->kind === DashboardScopeKind::AllTenants;
    }

    /**
     * The owner-scoping SQL fragment and its bindings against the RAW delivery/subscription
     * tables, to `whereRaw()` onto any builder or splice into a raw query. There a global
     * owner is genuinely NULL on both columns, so global mode is `IS NULL` (no bindings);
     * tenant mode matches the whole pair.
     *
     * @return array{0: literal-string, 1: list<int|string>}
     */
    public function condition(): array
    {
        if ($this->identity instanceof TenantIdentity) {
            return ['owner_type = ? AND owner_id = ?', [$this->identity->type, $this->identity->id]];
        }

        // All-tenants imposes no owner restriction at all. Returned as an always-true
        // fragment rather than an empty string because every caller splices this straight
        // into whereRaw() — an empty condition there is a SQL syntax error, and making each
        // call site branch on "did I get a condition?" is how one of them ends up unscoped
        // by accident.
        return $this->kind === DashboardScopeKind::AllTenants
            ? ['1 = 1', []]
            : ['owner_type IS NULL AND owner_id IS NULL', []];
    }

    /**
     * The owner-scoping fragment against the HOURLY ROLLUP, whose null-owner representation is
     * dialect-specific: PostgreSQL's rollup is a materialized VIEW that preserves the source's
     * NULL owner, while MySQL's is a TABLE whose unique key cannot span NULLs, so its refresh
     * COALESCEs a null owner to the ('' + owner_key_type zero) sentinel. Global mode therefore
     * matches `IS NULL` on PostgreSQL and that sentinel on MySQL; tenant mode is identical to
     * {@see self::condition()}
     * on both. Use this for every read of the rollup.
     *
     * @return array{0: literal-string, 1: list<int|string>}
     */
    public function rollupCondition(Dialect $dialect): array
    {
        if ($this->identity instanceof TenantIdentity) {
            return ['owner_type = ? AND owner_id = ?', [$this->identity->type, $this->identity->id]];
        }

        // All-tenants reads every rollup row, so the dialect's null-owner representation is
        // irrelevant here — there is nothing to match.
        if ($this->kind === DashboardScopeKind::AllTenants) {
            return ['1 = 1', []];
        }

        return $dialect === Dialect::MySql
            ? ['owner_type = ? AND owner_id = ?', ['', OwnerKeyType::fromConfig()->sentinelId()]]
            : ['owner_type IS NULL AND owner_id IS NULL', []];
    }

    /**
     * Whether this scope covers a row carrying the given owner pair — the per-row guard the
     * delivery policy uses. Global mode covers only owner-less rows; tenant mode matches the
     * whole pair, never the id alone.
     */
    public function includes(?string $ownerType, int|string|null $ownerId): bool
    {
        if ($this->identity instanceof TenantIdentity) {
            return $this->identity->owns($ownerType, $ownerId);
        }

        // All-tenants covers every row by definition, so the per-row guard admits them all.
        // The ACTION behind that guard is still gated separately (the delivery policy also
        // requires the manage ability) — this says what the scope can SEE, not what it may do.
        return $this->kind === DashboardScopeKind::AllTenants
            || ($ownerType === null && $ownerId === null);
    }
}
