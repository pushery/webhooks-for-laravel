<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Webhooks\Database\DatabaseRequirement;
use Webhooks\Database\Dialect\Dialect;
use Webhooks\Support\WebhookConnection;

return new class extends Migration
{
    /**
     * The standalone delivery log for the Server layer used without the Platform
     * layer. It is a flat, single-table record — one row per delivered message,
     * keyed by the Standard Webhooks message id — that the persistence listener
     * upserts across a delivery's lifecycle. Registered only when
     * webhooks.server.persistence.enabled, so an app that never opts in gains no table.
     *
     * The flat shape is portable, so this is the first table to run on either engine.
     * PostgreSQL keeps its exact original schema; MySQL gets a shape that is behaviourally
     * identical — UTC-naive DATETIME(6) instead of timestamptz, MEDIUMTEXT for the error,
     * and a case-sensitive collation on the identity columns.
     */
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
        Schema::dropIfExists('webhook_server_deliveries');
    }

    /**
     * Timestamps are timestamptz so a consumer can bucket created_at over a fixed zone,
     * matching the Platform delivery log.
     */
    private function createPostgres(): void
    {
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

    /**
     * Timestamps are DATETIME(6) holding UTC — never TIMESTAMP, which re-resolves against the
     * session zone and tops out in 2038. The message_id and uuid carry a case-sensitive
     * collation so two ids differing only in case never collapse onto one row (Postgres is
     * case-sensitive already). The error is MEDIUMTEXT so a long response never truncates.
     */
    private function createMySql(): void
    {
        Schema::create('webhook_server_deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->collation('utf8mb4_0900_as_cs')->unique();
            $table->string('message_id')->collation('utf8mb4_0900_as_cs')->unique();
            $table->string('url', 2048);
            $table->string('event_type')->nullable();
            $table->string('status', 20)->default('pending');
            $table->smallInteger('http_status')->nullable();
            $table->smallInteger('attempt')->default(0);
            $table->integer('duration_ms')->nullable();
            $table->mediumText('error')->nullable();
            $table->json('tags')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('delivered_at', 6)->nullable();

            $table->index('status');
            $table->index('created_at');
        });
    }
};
