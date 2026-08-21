<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * Adds the global newest-first index to an EXISTING webhook_deliveries table.
 *
 * The create-table migration carries this index now, which covers a fresh install and nothing
 * else: a host that already migrated will never re-run it, so without this file the fix would
 * reach only new installations while the release notes promised it to everyone. That is the
 * failure worth naming — a schema improvement that quietly skips every existing user, and a
 * changelog entry that is false for them.
 *
 * PostgreSQL only. The MySQL lane's flat table has carried `webhook_deliveries_created_idx`
 * since it was created, so re-adding it there would fail on the duplicate name.
 *
 * LOCKING, for a large log: this builds the index on the partitioned PARENT, which recursively
 * builds it on every existing partition and holds a lock on each for the duration. On a busy
 * multi-gigabyte log that is a real write-blocking window. PostgreSQL does not support
 * CREATE INDEX CONCURRENTLY on a partitioned parent, so the online path is manual and cannot be
 * expressed here: create the parent index `ON ONLY`, then `CREATE INDEX CONCURRENTLY` per
 * partition, then `ALTER INDEX … ATTACH PARTITION`. Do that by hand first if the window matters
 * — this migration is then a no-op, because it only creates the index when it is missing.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return WebhookConnection::name();
    }

    public function up(): void
    {
        // Same tier check the create-table migration makes: this table has a MySQL shape, so
        // PostgreSQL and MySQL 8.4+ are both supported and anything else is rejected here
        // rather than through a raw error further down.
        DatabaseRequirement::ensure($this->getConnection());

        if (Dialect::for($this->getConnection()) === Dialect::MySql) {
            return;
        }

        // IF NOT EXISTS so this is idempotent against a fresh install (whose create-table
        // migration already made it) and against a host that added the index by hand.
        DB::connection($this->getConnection())->statement(
            'CREATE INDEX IF NOT EXISTS webhook_deliveries_created_idx ON webhook_deliveries (created_at)'
        );
    }

    public function down(): void
    {
        if (Dialect::for($this->getConnection()) === Dialect::MySql) {
            return;
        }

        DB::connection($this->getConnection())->statement('DROP INDEX IF EXISTS webhook_deliveries_created_idx');
    }
};
