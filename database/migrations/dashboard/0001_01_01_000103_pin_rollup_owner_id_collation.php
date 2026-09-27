<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Database\OwnerKeyType;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * Pins `webhook_delivery_hourly.owner_id` to the case- and accent-sensitive collation on an
 * EXISTING MySQL rollup table whose owners are keyed by uuid or ulid.
 *
 * The column is the second one of the rollup's unique index, and as a CHAR column it inherited
 * the table's collation, which for a Blueprint-created table comes from the connection config.
 * Laravel ships utf8mb4_unicode_ci there. `webhooks:preflight` then failed a table this package
 * had created, and told the host that the shipped schema declared a collation it did not. The
 * create migration declares it now, which covers a fresh install and nothing else; this file
 * brings a table migrated before that into the same shape.
 *
 * MySQL only, uuid and ulid only, and only when the collation is actually wrong: an
 * `ALTER TABLE ... MODIFY` rewrites the table, and the rollup can be large. A column without a
 * collation is not a string column, whatever the config says now, and is left alone. The column
 * holds derived data that `webhooks:refresh-metrics` rebuilds, so no content is at risk here.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return WebhookConnection::name();
    }

    public function up(): void
    {
        DatabaseRequirement::ensure($this->getConnection());

        if (Dialect::for($this->getConnection()) !== Dialect::MySql) {
            return;
        }

        $ownerKeyType = OwnerKeyType::fromConfig();

        if ($ownerKeyType === OwnerKeyType::Bigint) {
            return;
        }

        $connection = DB::connection($this->getConnection());

        if (! $connection->getSchemaBuilder()->hasTable('webhook_delivery_hourly')) {
            return;
        }

        /** @var list<object{collation_name: string|null}> $rows */
        $rows = $connection->select(
            'SELECT COLLATION_NAME AS collation_name FROM information_schema.COLUMNS '
            ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_delivery_hourly' AND COLUMN_NAME = 'owner_id'",
        );

        $current = $rows[0]->collation_name ?? null;

        // Already distinguishing, or not a string column at all? Leave it. A binary collation is
        // stricter than the shipped one, and rewriting a large table to swap one correct
        // collation for another is cost without a result.
        if (! is_string($current) || str_ends_with($current, '_as_cs') || str_ends_with($current, '_bin')) {
            return;
        }

        $connection->statement(sprintf(
            "ALTER TABLE webhook_delivery_hourly MODIFY owner_id %s COLLATE utf8mb4_0900_as_cs NOT NULL DEFAULT '%s'",
            $ownerKeyType->rawType(Dialect::MySql),
            $ownerKeyType->sentinelId(),
        ));
    }

    /**
     * Deliberately empty. Down would have to put back a collation this package never chose —
     * whatever the host's database default happened to be — and guessing it wrong is worse than
     * leaving a column stricter than it was.
     */
    public function down(): void {}
};
