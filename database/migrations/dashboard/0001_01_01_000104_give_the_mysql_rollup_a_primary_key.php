<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * Gives an EXISTING MySQL rollup table its primary key.
 *
 * The create migration declares the key on the owner pair and the bucket now, which covers a fresh
 * install and nothing else: a host that already migrated will never re-run it, and its table keeps
 * a unique index on the same three columns and no primary key. A server that turns
 * sql_require_primary_key on later, or a move to Group Replication, then refuses every change to
 * that table.
 *
 * MySQL only, and only while the table has no primary key. On PostgreSQL the rollup is a
 * materialized view and the question does not arise. The unique index the key replaces is dropped
 * right after it: it indexes exactly the key's columns, so it would only be kept up to date twice.
 * The rows are unique on those columns already, which is what the index guaranteed, so the key can
 * be added without touching a row.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return WebhookConnection::name();
    }

    public function up(): void
    {
        // Default-deny, like every migration in this package: DatabaseRequirement rather than
        // PostgresRequirement, because this file is the MySQL half and has to be allowed to run there.
        DatabaseRequirement::ensure($this->getConnection());

        if (Dialect::for($this->getConnection()) !== Dialect::MySql) {
            return;
        }

        $connection = DB::connection($this->getConnection());

        if (! $connection->getSchemaBuilder()->hasTable('webhook_delivery_hourly')) {
            return;
        }

        $count = static function (mixed $row): int {
            $n = is_object($row) ? (get_object_vars($row)['n'] ?? null) : null;

            return is_numeric($n) ? (int) $n : 0;
        };

        $keys = $count($connection->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS '
            ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_delivery_hourly' AND CONSTRAINT_TYPE = 'PRIMARY KEY'",
        ));

        if ($keys > 0) {
            return;
        }

        $unique = $count($connection->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS '
            ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_delivery_hourly' AND INDEX_NAME = 'webhook_delivery_hourly_uidx'",
        ));

        Schema::connection($this->getConnection())->table('webhook_delivery_hourly', static function (Blueprint $table) use ($unique): void {
            $table->primary(['owner_type', 'owner_id', 'bucket']);

            if ($unique > 0) {
                $table->dropUnique('webhook_delivery_hourly_uidx');
            }
        });
    }

    /**
     * Deliberately empty. Down would take the primary key away again, which a server with
     * sql_require_primary_key on refuses, and leave the table in the state this migration exists to
     * end.
     */
    public function down(): void {}
};
