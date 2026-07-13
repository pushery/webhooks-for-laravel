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

    public function down(): void
    {
        Schema::dropIfExists('webhook_calls');
    }
};
