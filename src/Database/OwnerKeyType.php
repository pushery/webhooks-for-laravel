<?php

declare(strict_types=1);

namespace Webhooks\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Webhooks\Database\Dialect\Dialect;

/**
 * The storage type of the denormalised owner key (`owner_id`) — the one decision that has to
 * hold identically across every table the owner morph pair spans: the subscriptions table,
 * the delivery log and the dashboard rollup. A polymorphic owner may be keyed by a bigint
 * (the default), a UUID or a ULID; the package renders each table's `owner_id` column, the
 * MySQL rollup's null-owner sentinel and the subscribe-time key guard from this one enum, so
 * the three tables can never disagree and a UUID/ULID host is a config flag, not a migration
 * fork it has to re-apply after every `vendor:publish --force`.
 *
 * Set once, BEFORE migrating, via `webhooks.platform.owner_key_type`; changing it on a
 * populated database is a schema migration, not a runtime toggle.
 *
 * @internal
 */
enum OwnerKeyType: string
{
    case Bigint = 'bigint';
    case Uuid = 'uuid';
    case Ulid = 'ulid';

    /**
     * The configured owner-key type, defaulting to bigint. A typo throws here — at the point
     * of use (a migration, the subscribe guard, the model cast) — rather than silently
     * reshaping the schema or storing an owner the reader can never match.
     */
    public static function fromConfig(): self
    {
        $configured = Config::get('webhooks.platform.owner_key_type', self::Bigint->value);

        if (is_string($configured) && ($type = self::tryFrom($configured)) instanceof self) {
            return $type;
        }

        throw new InvalidArgumentException(
            'webhooks.platform.owner_key_type must be one of "bigint" (default), "uuid" or "ulid". '
            .'It fixes the storage type of the owner_id column across the subscriptions table, the '
            .'delivery log and the dashboard rollup, so it must be set before the tables are migrated '
            .'and match the primary-key type of the models that own webhook subscriptions.'
        );
    }

    /**
     * Define the `owner_id` column on a Blueprint (the subscriptions table and the MySQL
     * rollup table). Returns the column so the caller can chain `->nullable()` / `->default()`.
     */
    public function blueprintColumn(Blueprint $table, string $name): ColumnDefinition
    {
        return match ($this) {
            self::Bigint => $table->unsignedBigInteger($name),
            self::Uuid => $table->uuid($name),
            self::Ulid => $table->ulid($name),
        };
    }

    /**
     * The raw column type for the delivery log's `owner_id`, whose migration is hand-written
     * SQL (partitioned/collated DDL a Blueprint can't express). PostgreSQL has a native `uuid`
     * type; MySQL stores a UUID as fixed `char(36)`. A ULID is 26 Crockford-base32 chars on
     * both engines.
     */
    public function rawType(Dialect $dialect): string
    {
        return match ($this) {
            self::Bigint => 'bigint',
            self::Uuid => $dialect === Dialect::MySql ? 'char(36)' : 'uuid',
            self::Ulid => 'char(26)',
        };
    }

    /**
     * The non-null sentinel a global (owner-less) row takes in the MySQL rollup TABLE, whose
     * unique key cannot span NULLs (PostgreSQL's rollup is a view and keeps the source NULL).
     * It must be a valid value of the column type and can never collide with a real owner key:
     * 0 is never a real bigint owner id, and neither the nil UUID nor the all-zero ULID is ever
     * issued by a key generator (a ULID encodes a timestamp, so its minimum is unreachable).
     */
    public function sentinelId(): int|string
    {
        return match ($this) {
            self::Bigint => 0,
            self::Uuid => '00000000-0000-0000-0000-000000000000',
            self::Ulid => '00000000000000000000000000',
        };
    }

    /**
     * Whether the given owner primary key is storable under this type — the subscribe-time
     * guard that turns an owner/config mismatch into a clear error instead of an opaque insert
     * failure on the first fan-out. A bigint owner may arrive as an int or a numeric string
     * (a driver returns bigints as strings); a UUID or ULID owner is a string of that shape.
     */
    public function accepts(int|string $key): bool
    {
        return match ($this) {
            self::Bigint => is_int($key) || ($key !== '' && ctype_digit($key)),
            self::Uuid => is_string($key) && Str::isUuid($key),
            self::Ulid => is_string($key) && Str::isUlid($key),
        };
    }
}
