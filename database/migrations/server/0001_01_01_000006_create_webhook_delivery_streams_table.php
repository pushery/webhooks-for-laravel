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
     * The streams of ordered delivery: one row per ordered delivery that has not succeeded yet,
     * deleted once it succeeds or is skipped, so the table holds what is in flight and what holds
     * a stream, never history. A stream is the URL and the ordering key, hashed into a value of
     * fixed width; the oldest position of a stream is the delivery whose turn it is.
     *
     * The unique index on the stream and the position is what keeps the order: two dispatches
     * that read the same highest position cannot both write the next one. The one on the stream
     * and the message id keeps a delivery from joining its stream twice.
     *
     * Registered while webhooks.server.ordering.enabled or webhooks.server.persistence.enabled
     * is on, with the rest of the Server layer's tables.
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
        Schema::dropIfExists('webhook_delivery_streams');
    }

    /**
     * Timestamps are timestamptz, like every other table of the package on PostgreSQL.
     */
    private function createPostgres(): void
    {
        Schema::create('webhook_delivery_streams', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('stream', 64);
            $table->unsignedBigInteger('position');
            $table->string('message_id');
            $table->timestampTz('held_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['stream', 'position']);
            $table->unique(['stream', 'message_id']);
        });
    }

    /**
     * Timestamps are DATETIME(6) holding UTC, and the two identity columns carry a case-sensitive
     * collation, so two message ids differing only in case stay two deliveries, as they are on
     * PostgreSQL.
     */
    private function createMySql(): void
    {
        Schema::create('webhook_delivery_streams', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('stream', 64)->collation('utf8mb4_0900_as_cs');
            $table->unsignedBigInteger('position');
            $table->string('message_id')->collation('utf8mb4_0900_as_cs');
            $table->dateTime('held_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();

            $table->unique(['stream', 'position']);
            $table->unique(['stream', 'message_id']);
        });
    }
};
