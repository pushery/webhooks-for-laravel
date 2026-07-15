<?php

declare(strict_types=1);

namespace Webhooks\Database\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Webhooks\Support\Timestamp;

/**
 * Query scopes that bind a moment against this package's timestamp columns as an
 * UNAMBIGUOUS literal — the one thing a host querying these tables directly must get
 * right and, without them, silently gets wrong.
 *
 * Every timestamp column the package ships is timestamptz on PostgreSQL and a UTC-naive
 * DATETIME(6) on MySQL. A plain `->where('created_at', '<', now()->subMinutes(15))` binds
 * a NAIVE literal, which PostgreSQL resolves against the database SESSION time zone — a
 * connection setting unrelated to app.timezone and routinely not UTC. The comparison is
 * then off by exactly that offset and the query quietly returns the wrong rows: no error,
 * no warning. These scopes bind the moment the way the package binds its own writes and
 * reads, per dialect, so the caller cannot get it wrong.
 *
 * Exposing the raw format as a constant would not be enough: the correct literal DIFFERS
 * by engine (PostgreSQL carries the offset, MySQL is UTC-naive), so a copied constant is
 * right on one engine and wrong on the other. Only a dialect-aware binding — a scope, or
 * {@see self::boundTimestamp()} for a raw statement — is safe on both.
 */
trait ScopesByTimestamp
{
    /**
     * Constrain any timestamp column with a comparison operator, binding the moment
     * unambiguously for the connection's dialect — the general primitive the named
     * scopes below build on, and the one to reach for on a column like delivered_at.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereTimestamp(Builder $query, string $column, string $operator, DateTimeInterface $moment): Builder
    {
        return $query->where($column, $operator, $this->boundTimestamp($moment));
    }

    /**
     * Rows created strictly before $moment.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCreatedBefore(Builder $query, DateTimeInterface $moment): Builder
    {
        return $query->where('created_at', '<', $this->boundTimestamp($moment));
    }

    /**
     * Rows created at or after $moment.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCreatedAfter(Builder $query, DateTimeInterface $moment): Builder
    {
        return $query->where('created_at', '>=', $this->boundTimestamp($moment));
    }

    /**
     * Rows created within the half-open interval [$from, $to): at or after $from and
     * strictly before $to, so two adjacent windows never both count the boundary row.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCreatedBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $to): Builder
    {
        return $query
            ->where('created_at', '>=', $this->boundTimestamp($from))
            ->where('created_at', '<', $this->boundTimestamp($to));
    }

    /**
     * The moment as the literal this connection's dialect resolves WITHOUT consulting the
     * session zone: an offset-bearing timestamptz on PostgreSQL, a UTC-naive DATETIME(6) on
     * MySQL. Public so a host building a raw statement the scopes don't cover can still bind
     * correctly — `(new WebhookDelivery)->boundTimestamp($moment)` — instead of copying the
     * format and drifting when it changes.
     */
    public function boundTimestamp(DateTimeInterface $moment): string
    {
        return $this->getConnection()->getDriverName() === 'mysql'
            ? Timestamp::mysql($moment)
            : Timestamp::sql($moment);
    }
}
