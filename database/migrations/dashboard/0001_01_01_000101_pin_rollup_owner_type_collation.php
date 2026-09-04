<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * Pins `webhook_delivery_hourly.owner_type` to the case- and accent-sensitive collation on an
 * EXISTING MySQL rollup table.
 *
 * The create migration carries the collation now, which covers a fresh install and nothing else:
 * a host that already migrated will never re-run it. Without this file the fix would reach only
 * new installations — and `webhooks:preflight` would then FAIL for every existing host whose
 * database default is case-insensitive, over a column they have no way to correct from here.
 *
 * MySQL only, and only when the collation is actually wrong. On PostgreSQL the question does not
 * arise; on a host that already runs a case-sensitive or binary database default the column is
 * already right and this is a no-op, which matters because an `ALTER TABLE ... MODIFY` rewrites
 * the table and the rollup can be large.
 *
 * The column is derived data — `webhooks:refresh-metrics` rebuilds every row — so no content is
 * at risk here. What the ALTER protects is the unique index the refresh inserts against.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return WebhookConnection::name();
    }

    public function up(): void
    {
        // Default-deny: every migration in this package states which engines it is willing to run
        // against, and one that guards with neither fails with a raw driver error on the wrong
        // one. DatabaseRequirement rather than PostgresRequirement, because this file is the
        // MySQL half — it has to be allowed to run there.
        DatabaseRequirement::ensure($this->getConnection());

        if (Dialect::for($this->getConnection()) !== Dialect::MySql) {
            return;
        }

        $connection = DB::connection($this->getConnection());

        if (! $connection->getSchemaBuilder()->hasTable('webhook_delivery_hourly')) {
            return;
        }

        /** @var list<object{collation_name: string|null}> $rows */
        $rows = $connection->select(
            'SELECT COLLATION_NAME AS collation_name FROM information_schema.COLUMNS '
            ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_delivery_hourly' AND COLUMN_NAME = 'owner_type'",
        );

        $current = $rows[0]->collation_name ?? null;

        // Already distinguishing? Leave it. A binary collation is stricter than the shipped one,
        // and rewriting a large table to swap one correct collation for another is cost without
        // a result.
        if (is_string($current) && (str_ends_with($current, '_as_cs') || str_ends_with($current, '_bin'))) {
            return;
        }

        $connection->statement(
            "ALTER TABLE webhook_delivery_hourly MODIFY owner_type VARCHAR(255) COLLATE utf8mb4_0900_as_cs NOT NULL DEFAULT ''",
        );
    }

    /**
     * Deliberately empty. Down would have to put back a collation this package never chose —
     * whatever the host's database default happened to be — and guessing it wrong is worse than
     * leaving a column stricter than it was.
     */
    public function down(): void {}
};
