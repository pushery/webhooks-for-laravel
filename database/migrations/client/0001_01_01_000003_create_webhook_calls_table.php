<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webhooks\Database\DatabaseRequirement;
use Webhooks\Database\Dialect\Dialect;
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
        Schema::dropIfExists('webhook_calls');
    }

    private function createPostgres(): void
    {
        Schema::create('webhook_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source');                  // = the config entry name
            $table->string('webhook_id')->nullable();  // producer's id (dedupe key)
            $table->string('event_type')->nullable();
            $table->jsonb('payload');

            // The EXACT bytes that were received and signature-verified, base64-encoded.
            // body_sha256 is a promise of byte fidelity — the reason to keep a hash at all
            // is to re-verify or forward the call later — and the parsed payload cannot
            // keep it: re-encoding a decoded envelope changes whitespace, slash and unicode
            // escaping and float formatting, and a body that does not decode at all (invalid
            // UTF-8, a lone surrogate, a truncated payload) would leave nothing behind. The
            // bytes are base64 because a received body is arbitrary bytes: a NUL byte or an
            // invalid UTF-8 sequence is rejected outright by a Postgres text column, and a
            // bytea column round-trips through PDO as a stream rather than a string. Null
            // when the body was offloaded to a Storage disk, which keeps the bytes instead.
            $table->text('raw_body')->nullable();
            $table->string('payload_disk')->nullable();
            $table->string('payload_path')->nullable();
            $table->char('body_sha256', 64);
            $table->jsonb('headers')->nullable();       // redacted before storage
            $table->string('status')->default('received');
            $table->text('exception')->nullable();
            $table->timestampsTz();
        });

        // Partial unique dedupe key: two deliveries of the same producer id to the
        // same source collapse to one row. The predicate makes it partial so a
        // producer that sends no id is never blocked from inserting.
        DB::statement('CREATE UNIQUE INDEX webhook_calls_dedupe_uidx ON webhook_calls (source, webhook_id) WHERE webhook_id IS NOT NULL');

        // A stored generated column mirrors the payload's own "type" field so it can
        // be queried and indexed without re-parsing the jsonb on every read.
        DB::statement("ALTER TABLE webhook_calls ADD COLUMN payload_type text GENERATED ALWAYS AS (payload->>'type') STORED");

        // Worklist scans only look at rows still awaiting processing; a partial index
        // keeps them small even as processed rows accumulate.
        DB::statement("CREATE INDEX webhook_calls_unprocessed_idx ON webhook_calls (created_at) WHERE status = 'received'");

        // The scheduled prune deletes by created_at across ALL statuses, but old rows
        // are dominated by processed/failed rows that the partial index above excludes,
        // so the prune needs a plain (non-partial) created_at index to avoid a
        // sequential scan of the whole table on every run.
        DB::statement('CREATE INDEX webhook_calls_created_idx ON webhook_calls (created_at)');

        // GIN over jsonb_path_ops accelerates containment queries into the payload.
        DB::statement('CREATE INDEX webhook_calls_payload_gin ON webhook_calls USING gin (payload jsonb_path_ops)');
    }

    private function createMySql(): void
    {
        // Case-sensitive collation on the identity/dedupe columns so MySQL's case-insensitive
        // default cannot collapse a distinct (source, webhook_id) — or a distinct hash — onto an
        // existing row. raw_body is LONGTEXT (a base64 body far exceeds TEXT's 64 KB), and the
        // exception is MEDIUMTEXT. There is no partial-index or GIN equivalent on MySQL: the
        // dedupe key is a plain UNIQUE (NULL is DISTINCT in a unique index on both engines, so a
        // null webhook_id never blocks an insert — exactly the partial predicate's effect), the
        // worklist index leads with status, and payload containment search is served by Scout.
        $cs = 'utf8mb4_0900_as_cs';

        Schema::create('webhook_calls', function (Blueprint $table) use ($cs): void {
            $table->char('id', 36)->collation($cs)->primary();
            $table->string('source')->collation($cs);
            $table->string('webhook_id')->collation($cs)->nullable();
            $table->string('event_type')->collation($cs)->nullable();
            $table->json('payload');
            $table->longText('raw_body')->nullable();
            $table->string('payload_disk')->nullable();
            $table->string('payload_path')->nullable();
            $table->char('body_sha256', 64)->collation($cs);
            $table->json('headers')->nullable();
            $table->string('status')->collation($cs)->default('received');
            $table->mediumText('exception')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['source', 'webhook_id'], 'webhook_calls_dedupe_uidx');
            $table->index(['status', 'created_at'], 'webhook_calls_unprocessed_idx');
            $table->index('created_at', 'webhook_calls_created_idx');
        });

        // payload->>'$.type' would double-unquote (MySQL's ->> already is JSON_UNQUOTE); use
        // JSON_UNQUOTE(JSON_EXTRACT(...)) once. Uncapped MEDIUMTEXT, never queried, so no width
        // truncation can ever diverge from the PostgreSQL value.
        DB::statement("ALTER TABLE webhook_calls ADD COLUMN payload_type MEDIUMTEXT GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.type'))) STORED");
    }
};
