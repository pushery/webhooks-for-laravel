<?php

declare(strict_types=1);

namespace Webhooks\Dashboard;

use Webhooks\Database\Dialect\Dialect;
use Webhooks\Support\TenantIdentity;

/**
 * What a dashboard read is scoped to: a single tenant (the WHOLE owner morph pair) or, in
 * operator mode, the GLOBAL, owner-less rows (`owner_type IS NULL AND owner_id IS NULL`) —
 * the endpoints an operator registers with a null owner, which no tenant scope can see.
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
    private function __construct(private ?TenantIdentity $identity) {}

    public static function forTenant(TenantIdentity $identity): self
    {
        return new self($identity);
    }

    /**
     * The operator scope: the global, owner-less rows only. Never all tenants' rows — an
     * operator observes the endpoints it owns globally, not another tenant's private ones.
     */
    public static function global(): self
    {
        return new self(null);
    }

    public function isGlobal(): bool
    {
        return ! $this->identity instanceof TenantIdentity;
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
        return $this->identity instanceof TenantIdentity
            ? ['owner_type = ? AND owner_id = ?', [$this->identity->type, $this->identity->id]]
            : ['owner_type IS NULL AND owner_id IS NULL', []];
    }

    /**
     * The owner-scoping fragment against the HOURLY ROLLUP, whose null-owner representation is
     * dialect-specific: PostgreSQL's rollup is a materialized VIEW that preserves the source's
     * NULL owner, while MySQL's is a TABLE whose unique key cannot span NULLs, so its refresh
     * COALESCEs a null owner to the ('', 0) sentinel. Global mode therefore matches `IS NULL` on
     * PostgreSQL and that sentinel on MySQL; tenant mode is identical to {@see self::condition()}
     * on both. Use this for every read of the rollup.
     *
     * @return array{0: literal-string, 1: list<int|string>}
     */
    public function rollupCondition(Dialect $dialect): array
    {
        if ($this->identity instanceof TenantIdentity) {
            return ['owner_type = ? AND owner_id = ?', [$this->identity->type, $this->identity->id]];
        }

        return $dialect === Dialect::MySql
            ? ['owner_type = ? AND owner_id = ?', ['', 0]]
            : ['owner_type IS NULL AND owner_id IS NULL', []];
    }

    /**
     * Whether this scope covers a row carrying the given owner pair — the per-row guard the
     * delivery policy uses. Global mode covers only owner-less rows; tenant mode matches the
     * whole pair, never the id alone.
     */
    public function includes(?string $ownerType, int|string|null $ownerId): bool
    {
        return $this->identity instanceof TenantIdentity
            ? $this->identity->owns($ownerType, $ownerId)
            : $ownerType === null && $ownerId === null;
    }
}
