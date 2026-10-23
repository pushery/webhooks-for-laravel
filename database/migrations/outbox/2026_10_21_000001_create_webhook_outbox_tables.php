<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Support\WebhookConnection;

return new class extends Migration
{
    /**
     * The transactional outbox: one row per recorded webhook, written in the transaction of the
     * change it reports, and one counter per stream that hands out the entries' sequence in commit
     * order. Registered only when webhooks.server.outbox.enabled, so an app that never records into
     * the outbox gains neither table.
     *
     * The due scan reads (state, next_attempt_at), the head of a stream is found through the unique
     * (stream, sequence), and the prune reads (state, ended_at). An entry outside a stream has a null
     * stream and sequence, which the unique index admits any number of times on both engines.
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
        Schema::dropIfExists('webhook_outbox_streams');
        Schema::dropIfExists('webhook_outbox');
    }

    /**
     * Timestamps are timestamptz, like every other table of the package on PostgreSQL.
     */
    private function createPostgres(): void
    {
        Schema::create('webhook_outbox', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // The Standard Webhooks webhook-id every attempt of the entry carries.
            $table->string('message_id')->unique();
            $table->char('stream', 64)->nullable();
            $table->bigInteger('sequence')->nullable();
            $table->text('url');
            $table->string('event_type')->nullable();
            $table->jsonb('meta')->nullable();
            // The whole delivery, sealed under the application key.
            $table->text('delivery');
            $table->string('state', 16)->default('pending');
            $table->integer('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->uuid('claimed_by')->nullable();
            $table->smallInteger('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('alarmed_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['stream', 'sequence']);
            $table->index(['state', 'next_attempt_at']);
            $table->index(['state', 'ended_at']);
        });

        Schema::create('webhook_outbox_streams', function (Blueprint $table): void {
            $table->char('stream', 64)->primary();
            $table->bigInteger('last_sequence')->default(0);
        });
    }

    /**
     * Timestamps are DATETIME(6) holding UTC, and the identity columns carry a case-sensitive
     * collation, so two message ids differing only in case stay two entries, as they are on
     * PostgreSQL. The sealed delivery is LONGTEXT, because a body may be far larger than TEXT holds.
     */
    private function createMySql(): void
    {
        Schema::create('webhook_outbox', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('message_id')->collation('utf8mb4_0900_as_cs')->unique();
            $table->char('stream', 64)->collation('utf8mb4_0900_as_cs')->nullable();
            $table->unsignedBigInteger('sequence')->nullable();
            $table->text('url');
            $table->string('event_type')->nullable();
            $table->json('meta')->nullable();
            $table->longText('delivery');
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at', 6)->nullable();
            $table->char('claimed_by', 36)->nullable();
            $table->smallInteger('last_status')->nullable();
            $table->mediumText('last_error')->nullable();
            $table->dateTime('alarmed_at', 6)->nullable();
            $table->dateTime('ended_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['stream', 'sequence']);
            $table->index(['state', 'next_attempt_at']);
            $table->index(['state', 'ended_at']);
        });

        Schema::create('webhook_outbox_streams', function (Blueprint $table): void {
            $table->char('stream', 64)->collation('utf8mb4_0900_as_cs')->primary();
            $table->unsignedBigInteger('last_sequence')->default(0);
        });
    }
};
