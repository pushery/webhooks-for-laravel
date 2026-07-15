<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webhooks\Database\DatabaseRequirement;
use Webhooks\Database\Dialect\Dialect;
use Webhooks\Database\OwnerKeyType;
use Webhooks\Support\WebhookConnection;

return new class extends Migration
{
    public function up(): void
    {
        DatabaseRequirement::ensure($this->getConnection());

        if (Dialect::for($this->getConnection()) === Dialect::MySql) {
            $this->createMySql();

            return;
        }

        $this->createPostgres();
    }

    public function getConnection(): ?string
    {
        return WebhookConnection::name();
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }

    /**
     * Every timestamp column on this table is timestamptz, matching both delivery logs: the
     * columns are written in UTC via Eloquent today, but the fixed-zone type keeps any future
     * raw-SQL comparison against now() from silently applying the session zone.
     */
    private function createPostgres(): void
    {
        $ownerKeyType = OwnerKeyType::fromConfig();

        Schema::create('webhook_subscriptions', function (Blueprint $table) use ($ownerKeyType): void {
            $table->id();

            // owner_id is denormalised across the delivery log and the dashboard rollup, so its
            // type is not nullableMorphs() but the configured owner_key_type (bigint by default,
            // uuid/ulid on demand), rendered identically here and on those tables. WebhookManager
            // rejects an owner whose key does not match the configured type up front.
            $table->string('owner_type')->nullable();
            $ownerKeyType->blueprintColumn($table, 'owner_id')->nullable();
            $table->index(['owner_type', 'owner_id'], 'webhook_subscriptions_owner_type_owner_id_index');
            $table->string('name')->nullable();
            $table->text('url');
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestampTz('secret_rotated_at')->nullable();
            $table->jsonb('event_types');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('disabled_at')->nullable();
            $table->integer('consecutive_failures')->default(0);

            // Per-endpoint payload shaping. payload_version names the schema version
            // this endpoint receives; transform holds the declarative mapping rules
            // (include/exclude/rename/rewrap) applied to the event data before signing.
            $table->string('payload_version', 20)->nullable();
            $table->jsonb('transform')->nullable();

            // Cached endpoint health: a 0-100 score plus its coarse status, computed
            // from the recent delivery history and refreshed out of band, so a read
            // never recomputes. health_calculated_at records the last refresh.
            $table->smallInteger('health_score')->nullable();
            $table->string('health_status', 20)->nullable();
            $table->timestampTz('health_calculated_at')->nullable();

            $table->timestampsTz();
        });

        // GIN index accelerates the fan-out lookup "which subscriptions listen for
        // event type X" (WHERE event_types @> '["X"]'). jsonb_path_ops is the
        // smallest, fastest operator class for pure containment queries.
        DB::statement('CREATE INDEX webhook_subscriptions_event_types_gin ON webhook_subscriptions USING gin (event_types jsonb_path_ops)');
    }

    /**
     * Timestamps are DATETIME(6) holding UTC. owner_type carries a case-sensitive collation so
     * tenant scoping never conflates two morph classes. The fan-out lookup is served by a
     * multi-valued index over the event_types array, which stock whereJsonContains uses.
     */
    private function createMySql(): void
    {
        $cs = 'utf8mb4_0900_as_cs';
        $ownerKeyType = OwnerKeyType::fromConfig();

        Schema::create('webhook_subscriptions', function (Blueprint $table) use ($cs, $ownerKeyType): void {
            $table->id();
            $table->string('owner_type')->collation($cs)->nullable();
            $ownerKeyType->blueprintColumn($table, 'owner_id')->nullable();
            $table->index(['owner_type', 'owner_id'], 'webhook_subscriptions_owner_type_owner_id_index');
            $table->string('name')->nullable();
            $table->string('url', 2048);
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->dateTime('secret_rotated_at', 6)->nullable();
            $table->json('event_types');
            $table->boolean('is_active')->default(true);
            $table->dateTime('disabled_at', 6)->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->string('payload_version', 20)->nullable();
            $table->json('transform')->nullable();
            $table->smallInteger('health_score')->nullable();
            $table->string('health_status', 20)->collation($cs)->nullable();
            $table->dateTime('health_calculated_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        // A multi-valued index over the top-level event_types array is what stock
        // whereJsonContains('event_types', 'x') uses on MySQL — the fan-out stays an index
        // lookup, no projection table needed. CHAR(255) holds any event type name.
        DB::statement('ALTER TABLE webhook_subscriptions ADD INDEX webhook_subscriptions_event_types_mv ((CAST(event_types AS CHAR(255) ARRAY)))');
    }
};
