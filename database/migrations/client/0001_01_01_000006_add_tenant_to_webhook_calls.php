<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Support\TableLookup;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * Adds `tenant` to webhook_calls: the key of the tenant a delivery was addressed to, written for a config with a
 * `tenant_resolver` and null on every other delivery.
 *
 * Nullable and without a default, so neither engine rewrites the table to add it. It carries no index: the package
 * reads a delivery by its id and dedupes on (source, webhook_id), and a host whose row level security policy reads
 * the column adds the index its own queries need.
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

        $schema = Schema::connection($this->getConnection());

        if ($schema->hasColumn(TableLookup::qualified('webhook_calls', $this->getConnection()), 'tenant')) {
            return;
        }

        $this->boundLocks();

        $schema->table('webhook_calls', function (Blueprint $table): void {
            $table->string('tenant', 191)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasColumn(TableLookup::qualified('webhook_calls', $this->getConnection()), 'tenant')) {
            return;
        }

        $this->boundLocks();

        $schema->table('webhook_calls', function (Blueprint $table): void {
            $table->dropColumn('tenant');
        });
    }

    /**
     * The table takes deliveries all the time, so the short exclusive lock the ALTER needs is waited for three seconds
     * at most: the migration fails rather than holding every insert behind it, and running it again is the remedy. On
     * PostgreSQL the bound ends with the migration's transaction; MySQL has no such scope and keeps it for the session.
     */
    private function boundLocks(): void
    {
        $connection = Schema::connection($this->getConnection())->getConnection();

        if (Dialect::for($this->getConnection()) === Dialect::MySql) {
            $connection->statement('SET SESSION lock_wait_timeout = 3');

            return;
        }

        $connection->statement("SET LOCAL lock_timeout = '3s'");
        $connection->statement("SET LOCAL statement_timeout = '30s'");
    }
};
