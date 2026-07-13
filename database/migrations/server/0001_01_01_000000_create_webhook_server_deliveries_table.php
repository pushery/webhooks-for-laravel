<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Webhooks\Database\PostgresRequirement;

return new class extends Migration
{
    /**
     * The standalone delivery log for the Server layer used without the Platform
     * layer. It is a flat, single-table record — one row per delivered message,
     * keyed by the Standard Webhooks message id — that the persistence listener
     * upserts across a delivery's lifecycle. Registered only when
     * webhooks.server.persistence.enabled, so an app that never opts in gains no
     * table. Timestamps are timestamptz so a consumer can bucket created_at over a
     * fixed zone, matching the Platform delivery log.
     */
    public function up(): void
    {
        PostgresRequirement::ensure($this->getConnection());

        Schema::create('webhook_server_deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            // The Standard Webhooks webhook-id: stable across a redelivery, so the
            // listener upserts every attempt of one message onto a single row.
            $table->string('message_id')->unique();
            $table->text('url');
            $table->string('event_type')->nullable();
            $table->string('status', 20)->default('pending');
            $table->smallInteger('http_status')->nullable();
            $table->smallInteger('attempt')->default(0);
            $table->integer('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->jsonb('tags')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();

            // Reporting scans filter by status; pruning deletes by age.
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_server_deliveries');
    }
};
