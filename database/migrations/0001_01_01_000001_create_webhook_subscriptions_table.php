<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webhooks\Database\PostgresRequirement;

return new class extends Migration
{
    public function up(): void
    {
        PostgresRequirement::ensure($this->getConnection());

        // Every timestamp column on this table is timestamptz, matching both delivery
        // logs (webhook_deliveries, webhook_server_deliveries): the columns are written
        // in UTC via Eloquent today, but the fixed-zone type keeps any future raw-SQL
        // comparison against now() (itself timestamptz) from silently applying the
        // session zone, and keeps the schema internally consistent.
        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('owner');
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

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
