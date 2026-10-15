<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Support\TableLookup;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * Drops the GIN index over `webhook_calls.payload` from an EXISTING PostgreSQL install.
 *
 * The create-table migration built `webhook_calls_payload_gin` for containment queries into the
 * payload, and nothing in this package runs one: a stored call is found by its source and producer
 * id, or by its status. Every received call still wrote its entries into the index, for a payload
 * that can run to many kilobytes.
 *
 * The create-table migration no longer builds it, and that covers a fresh install only. A host that
 * already migrated never runs that file again, so this one removes the index where it stands, and is
 * a no-op where it never stood. MySQL never had it.
 *
 * The index is looked up and dropped in the schema a query finds the table in. With a schema per
 * tenant and `search_path` set to `tenant, public`, an unqualified name would drop the first index of
 * that name along the path, which need not belong to the table this connection reads.
 *
 * A host that searches inside stored payloads by containment keeps the index with a migration of its
 * own; `down()` runs the statement that builds it.
 */
return new class extends Migration
{
    private const string INDEX = 'webhook_calls_payload_gin';

    public function getConnection(): ?string
    {
        return WebhookConnection::name();
    }

    public function up(): void
    {
        // The same tier check the create-table migration makes, for the same reason: reject an
        // unsupported engine here rather than through a raw error further down.
        DatabaseRequirement::ensure($this->getConnection());

        if (Dialect::for($this->getConnection()) === Dialect::MySql) {
            return;
        }

        if (! Schema::connection($this->getConnection())->hasIndex(TableLookup::qualified('webhook_calls', $this->getConnection()), self::INDEX)) {
            return;
        }

        $table = TableLookup::qualified('webhook_calls', $this->getConnection());
        $index = str_contains($table, '.')
            ? $this->quoted(Str::before($table, '.')).'.'.$this->quoted(self::INDEX)
            : $this->quoted(self::INDEX);

        DB::connection($this->getConnection())->statement('DROP INDEX '.$index);
    }

    /**
     * Builds the index again, as the create-table migration did before this release.
     */
    public function down(): void
    {
        if (Dialect::for($this->getConnection()) === Dialect::MySql) {
            return;
        }

        if (Schema::connection($this->getConnection())->hasIndex(TableLookup::qualified('webhook_calls', $this->getConnection()), self::INDEX)) {
            return;
        }

        $table = TableLookup::qualified('webhook_calls', $this->getConnection());

        DB::connection($this->getConnection())->statement(
            'CREATE INDEX '.self::INDEX.' ON '.DB::connection($this->getConnection())->getQueryGrammar()->wrapTable($table).' USING gin (payload jsonb_path_ops)',
        );
    }

    /**
     * A PostgreSQL identifier in double quotes. The grammar's wrap() reads the first part of a dotted
     * name as a table and puts the table prefix before it, which a schema name does not take.
     */
    private function quoted(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
