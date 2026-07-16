<?php

declare(strict_types=1);

namespace Webhooks\Client\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use stdClass;
use Webhooks\Client\InboundMessage;
use Webhooks\Client\Models\WebhookCall;
use Webhooks\Client\WebhookCallStatus;
use Webhooks\Core\Http\HeaderRedactor;
use Webhooks\Core\Payload\PayloadSanitizer;
use Webhooks\Database\Dialect\Dialect;
use Webhooks\Database\Dialect\Sql\ImportInsert;
use Webhooks\Support\DeterministicUuid;
use Webhooks\Support\Timestamp;
use Webhooks\Support\WebhookConnection;

/**
 * Copies a spatie/laravel-webhook-client `webhook_calls` backlog into this package's own
 * `webhook_calls` log, once, so a project adopting the Client layer keeps its inbound history
 * instead of starting empty. Live receipts already flow the moment the route is switched over;
 * this is only for the OLD rows.
 *
 * It is safe to run twice. Each imported row's primary key is derived deterministically from its
 * source (uuid5 over source + the spatie row id), so a second run re-derives the same ids and the
 * idempotent insert skips everything already present — no duplicated history, no matter how often
 * it runs. Run --dry-run first to see the counts without writing.
 *
 * Two honesty caveats, both forced by what spatie stored:
 *
 *  - spatie kept only the PARSED payload, never the raw received bytes, so an imported row cannot
 *    carry the producer's original body_sha256. This reconstructs it from the re-encoded payload —
 *    self-consistent (hash('sha256', $call->body()) === $call->body_sha256) but a reconstruction,
 *    not the wire bytes. Treat imported rows as historical records, not re-verifiable ones.
 *  - imported rows are written in a TERMINAL status (failed when the spatie row recorded an
 *    exception, otherwise processed), never 'received', and no processing job is dispatched. They
 *    are history; re-running a handler over months-old calls would fire real side effects again.
 *
 * @internal
 */
final class ImportSpatieCallsCommand extends Command
{
    /**
     * The fixed namespace the deterministic import ids are derived under. It must never change:
     * a new value would re-derive every id and a re-run would duplicate an already-imported backlog.
     */
    private const string IMPORT_NAMESPACE = 'b6f8e7d4-3c2a-4b1e-9f8d-0a1c2b3d4e5f';

    protected $signature = 'webhooks:import-spatie-calls
        {--source= : The value written to the `source` column. Defaults to each spatie row\'s own `name`; pass this to force one source for every imported row.}
        {--from-table=webhook_calls : The spatie webhook_calls table to read from.}
        {--from-connection= : The database connection the spatie table lives on. Defaults to the application default.}
        {--chunk=1000 : How many source rows to read per batch (bounds memory on a large backlog).}
        {--dry-run : Report what would be imported without writing anything.}';

    protected $description = 'Backfill this package\'s webhook_calls log from a spatie/laravel-webhook-client backlog (idempotent).';

    public function handle(): int
    {
        $override = $this->stringOption('source');
        $fromTable = $this->stringOption('from-table') ?? 'webhook_calls';
        $fromConnection = $this->stringOption('from-connection');
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        if (! Schema::connection($fromConnection)->hasTable($fromTable)) {
            $this->error(sprintf('Source table "%s" was not found on connection "%s".', $fromTable, $fromConnection ?? 'default'));

            return self::FAILURE;
        }

        $dialect = WebhookConnection::dialect();
        $sql = ImportInsert::spatieCalls($dialect);

        $imported = 0;
        $skipped = 0;
        $errored = 0;
        /** @var list<string> $errors */
        $errors = [];

        DB::connection($fromConnection)->table($fromTable)->orderBy('id')->chunkById(
            $chunk,
            function (Collection $rows) use ($override, $dialect, $sql, $dryRun, &$imported, &$skipped, &$errored, &$errors): void {
                /** @var array<string, list<mixed>> $pending id => bindings */
                $pending = [];

                foreach ($rows as $row) {
                    try {
                        $source = $this->resolveSource($row, $override);
                        $id = DeterministicUuid::v5(self::IMPORT_NAMESPACE, sprintf('spatie-import:%s:%s', $source, $this->spatieId($row)));
                        $pending[$id] = $this->bindings($row, $source, $id, $dialect);
                    } catch (JsonException $e) {
                        $errored++;

                        if (count($errors) < 10) {
                            $errors[] = sprintf('row %s: %s', $this->spatieId($row), $e->getMessage());
                        }
                    }
                }

                if ($dryRun) {
                    [$imp, $skp] = $this->countAgainstTarget(array_keys($pending));
                    $imported += $imp;
                    $skipped += $skp;

                    return;
                }

                foreach ($pending as $bindings) {
                    $this->write($sql, $bindings, $dialect) ? $imported++ : $skipped++;
                }
            },
            'id',
        );

        $this->report($dryRun, $imported, $skipped, $errored, $errors);

        return self::SUCCESS;
    }

    /**
     * The source value for a row: the --source override when given, otherwise the spatie row's own
     * `name` column (the documented name → source mapping), falling back to 'default' when absent.
     */
    private function resolveSource(stdClass $row, ?string $override): string
    {
        if ($override !== null) {
            return $override;
        }

        $name = $row->name ?? null;

        return is_string($name) && $name !== '' ? $name : 'default';
    }

    private function spatieId(stdClass $row): string
    {
        $id = $row->id ?? null;

        return is_scalar($id) ? (string) $id : '';
    }

    /**
     * The 13 positional bindings for one imported row, in the ImportInsert column order. The body
     * is the spatie payload re-encoded with the same flags the live receiver uses, so the stored
     * raw_body, its SHA-256 and the queryable payload view are all one self-consistent instant.
     *
     * @return list<mixed>
     *
     * @throws JsonException when the spatie payload or headers are not valid JSON
     */
    private function bindings(stdClass $row, string $source, string $id, Dialect $dialect): array
    {
        // spatie's payload column is json but nullable: a null or empty payload is a bodyless call,
        // imported as an empty object rather than an error. Genuinely INVALID JSON (only reachable
        // from a text-typed source column) throws and the caller records the row as unreadable.
        $payload = $row->payload ?? null;
        $json = is_string($payload) && $payload !== '' ? $payload : '{}';
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $body = json_encode(PayloadSanitizer::scrub(is_array($decoded) ? $decoded : []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $exception = $row->exception ?? null;
        $status = is_string($exception) && $exception !== '' ? WebhookCallStatus::Failed : WebhookCallStatus::Processed;

        return [
            $id,
            $source,
            null, // webhook_id — spatie never captured a producer id; dedupe rests on the deterministic key
            InboundMessage::fromRawBody($body)->type,
            $body,
            WebhookCall::encodeRawBody($body),
            null, // payload_disk — imports are never offloaded
            null, // payload_path
            hash('sha256', $body),
            $this->headersJson($row),
            $status->value,
            $this->timestamp($row->created_at ?? null, $dialect),
            $this->timestamp($row->updated_at ?? null, $dialect),
        ];
    }

    /**
     * The redacted-and-scrubbed headers JSON, or null when the spatie row stored none. The
     * credential-bearing headers (Authorization, Cookie) are masked exactly as the live receive
     * path masks them, so a backfilled row never carries a token the real path would have hidden.
     *
     * @throws JsonException when the stored headers are not valid JSON
     */
    private function headersJson(stdClass $row): ?string
    {
        $headers = $row->headers ?? null;

        if (! is_string($headers) || $headers === '') {
            return null;
        }

        $decoded = json_decode($headers, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return null;
        }

        return json_encode(PayloadSanitizer::scrub(HeaderRedactor::mask($decoded)), JSON_THROW_ON_ERROR);
    }

    /**
     * A source timestamp rendered for the target engine, preserving the original instant. spatie
     * stores UTC, so a naive value is read as UTC; a missing one falls back to now().
     */
    private function timestamp(mixed $value, Dialect $dialect): string
    {
        $moment = is_string($value) && $value !== '' ? Date::parse($value, 'UTC') : Date::now();

        return $dialect === Dialect::MySql ? Timestamp::mysql($moment) : Timestamp::sql($moment);
    }

    /**
     * Run one idempotent upsert; true when the row was inserted, false when it already existed.
     * PostgreSQL signals a duplicate with a null RETURNING; MySQL with a zero affected-row count.
     *
     * @param  list<mixed>  $bindings
     */
    private function write(string $sql, array $bindings, Dialect $dialect): bool
    {
        if ($dialect === Dialect::MySql) {
            return WebhookConnection::db()->affectingStatement($sql, $bindings) > 0;
        }

        return WebhookConnection::db()->selectOne($sql, $bindings) !== null;
    }

    /**
     * For --dry-run: how many of these ids are new versus already present in the target, without
     * writing. Split in one query so a huge backlog does not become one lookup per row.
     *
     * @param  list<string>  $ids
     * @return array{0: int, 1: int} [wouldImport, wouldSkip]
     */
    private function countAgainstTarget(array $ids): array
    {
        if ($ids === []) {
            return [0, 0];
        }

        $present = WebhookCall::query()->whereIn('id', $ids)->count();

        return [count($ids) - $present, $present];
    }

    /**
     * @param  list<string>  $errors
     */
    private function report(bool $dryRun, int $imported, int $skipped, int $errored, array $errors): void
    {
        $this->info($dryRun
            ? sprintf('Dry run: would import %d, would skip %d already present, %d could not be read.', $imported, $skipped, $errored)
            : sprintf('Imported %d call(s), skipped %d already present, %d could not be read.', $imported, $skipped, $errored));

        foreach ($errors as $error) {
            $this->warn('Skipped '.$error);
        }
    }

    /**
     * A string option, or null when it was not given (or given empty) — so an empty --source or
     * --from-connection reads as "use the default", not as the empty string.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
