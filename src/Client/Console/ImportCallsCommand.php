<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Pushery\Webhooks\Client\InboundMessage;
use Pushery\Webhooks\Client\Models\WebhookCall;
use Pushery\Webhooks\Client\WebhookCallStatus;
use Pushery\Webhooks\Core\Http\HeaderRedactor;
use Pushery\Webhooks\Core\Payload\PayloadSanitizer;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Database\Dialect\Sql\ImportInsert;
use Pushery\Webhooks\Support\DeterministicUuid;
use Pushery\Webhooks\Support\Timestamp;
use Pushery\Webhooks\Support\WebhookConnection;
use stdClass;

/**
 * Copies an inbound-webhook backlog from a table you used before this package into its own
 * `webhook_calls` log, once, so a project adopting the Client layer keeps its history instead of
 * starting empty. Live receipts already flow the moment the route is switched over; this is only
 * for the OLD rows.
 *
 * The source SHAPE is declared rather than assumed. Five `--from-*` options name the columns to
 * read, and their defaults describe the shape these tables almost always have — an `id`, a `name`
 * that says which producer it came from, a `payload`, its `headers`, and an `exception` recorded
 * when handling failed. A table that spells them differently needs no code, only the options.
 *
 * It is safe to run twice. Each imported row's primary key is derived deterministically from
 * (source, source row id), so a second run re-derives the same ids and the idempotent insert skips
 * everything already present — no duplicated history, no matter how often it runs. Run --dry-run
 * first to see the counts without writing.
 *
 * Importing two different tables without distinguishing them is the one way to lose rows. The key
 * is (source, row id), so two tables whose sources agree and whose ids overlap collapse onto each
 * other and the second import reports the collisions as "already present" — rows that are simply
 * not there afterwards. Give each import its own `--source`, which is what that option is for.
 *
 * Two honesty caveats, both forced by what such a table stores:
 *
 *  - it keeps only the PARSED payload, never the raw received bytes, so an imported row cannot
 *    carry the producer's original body_sha256. This reconstructs it from the re-encoded payload —
 *    self-consistent (hash('sha256', $call->body()) === $call->body_sha256) but a reconstruction,
 *    not the wire bytes. Treat imported rows as historical records, not re-verifiable ones.
 *  - imported rows are written in a TERMINAL status (failed when the source row recorded an error,
 *    otherwise processed), never 'received', and no processing job is dispatched. They are history;
 *    re-running a handler over months-old calls would fire real side effects again. The error TEXT
 *    is read to decide that status and is not carried across.
 *
 * @internal
 */
final class ImportCallsCommand extends Command
{
    /**
     * The fixed namespace the deterministic import ids are derived under. It must never change:
     * a new value would re-derive every id and a re-run would duplicate an already-imported backlog.
     */
    private const string IMPORT_NAMESPACE = 'b6f8e7d4-3c2a-4b1e-9f8d-0a1c2b3d4e5f';

    /**
     * The literal the derived key is built from, alongside the namespace above and for the same
     * reason: it is part of the hash, not a label on it.
     *
     * This value changed in 3.0, and that is a breaking change rather than a rename. Every id this
     * command derives is a function of it, so a backlog imported by an earlier version re-derives
     * to DIFFERENT ids now: the idempotent insert no longer recognizes those rows, and a re-run
     * would import the whole backlog a second time. It cannot be detected from here — the old rows
     * are indistinguishable from rows this package received itself.
     *
     * The upgrade guide says it in one line, and `--dry-run` shows it before any write: over a
     * backlog you already imported, it must report everything as already present. If it reports
     * rows to import, you are looking at exactly this, and the answer is not to run it.
     */
    private const string IMPORT_KEY = 'legacy-call-import';

    protected $signature = 'webhooks:import-calls
        {--source= : The value written to the `source` column. Defaults to each source row\'s own producer-name column; pass this to force one source for every imported row, and to keep two imports apart.}
        {--from-table=webhook_calls : The table to read from.}
        {--from-connection= : The database connection that table lives on. Defaults to the application default.}
        {--from-id=id : The source column holding each row\'s primary key. Read as-is; it is what the derived, idempotent target id is built from.}
        {--from-source=name : The source column naming the producer a row came from. Rows where it is empty import as `default`.}
        {--from-payload=payload : The source column holding the decoded JSON body.}
        {--from-headers=headers : The source column holding the request headers as JSON. Missing or empty imports as no headers.}
        {--from-error=exception : The source column that records a handling failure. Its PRESENCE marks the imported row failed; its text is not carried.}
        {--chunk=1000 : How many source rows to read per batch (bounds memory on a large backlog).}
        {--dry-run : Report what would be imported without writing anything.}';

    protected $description = 'Backfill this package\'s webhook_calls log from an inbound-webhook table you used before it (idempotent).';

    public function handle(): int
    {
        $override = $this->stringOption('source');
        $fromTable = $this->stringOption('from-table') ?? 'webhook_calls';
        $fromConnection = $this->stringOption('from-connection');
        $chunk = max(1, (int) $this->option('chunk'));
        $columns = new LegacyCallColumns(
            id: $this->stringOption('from-id') ?? 'id',
            source: $this->stringOption('from-source') ?? 'name',
            payload: $this->stringOption('from-payload') ?? 'payload',
            headers: $this->stringOption('from-headers') ?? 'headers',
            error: $this->stringOption('from-error') ?? 'exception',
        );
        // The cast changes no value: a boolean console option is already a bool. It stays because
        // option() is declared as mixed, and the variable below is used as a condition in three
        // places — the cast is where "mixed" stops.
        $dryRun = (bool) $this->option('dry-run');

        if (! Schema::connection($fromConnection)->hasTable($fromTable)) {
            $this->error(sprintf('Source table "%s" was not found on connection "%s".', $fromTable, $fromConnection ?? 'default'));

            return self::FAILURE;
        }

        $dialect = WebhookConnection::dialect();
        $sql = ImportInsert::calls($dialect);

        $imported = 0;
        $skipped = 0;
        $errored = 0;
        /** @var list<string> $errors */
        $errors = [];

        // Ordered and chunked by the DECLARED id column, not by a literal 'id'. chunkById walks
        // the table by that column, so leaving it hardcoded would order by a column a source table
        // need not have -- and the whole point of the map is that it need not.
        DB::connection($fromConnection)->table($fromTable)->orderBy($columns->id)->chunkById(
            $chunk,
            function (Collection $rows) use ($override, $columns, $dialect, $sql, $dryRun, &$imported, &$skipped, &$errored, &$errors): void {
                /** @var array<string, list<mixed>> $pending id => bindings */
                $pending = [];

                foreach ($rows as $row) {
                    try {
                        $source = $this->resolveSource($row, $columns, $override);
                        $id = DeterministicUuid::v5(self::IMPORT_NAMESPACE, sprintf('%s:%s:%s', self::IMPORT_KEY, $source, $this->sourceRowId($row, $columns)));
                        $pending[$id] = $this->bindings($row, $columns, $source, $id, $dialect);
                    } catch (JsonException $e) {
                        $errored++;

                        if (count($errors) < 10) {
                            $errors[] = sprintf('row %s: %s', $this->sourceRowId($row, $columns), $e->getMessage());
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
            $columns->id,
        );

        $this->report($dryRun, $imported, $skipped, $errored, $errors);

        return self::SUCCESS;
    }

    /**
     * The source value for a row: the --source override when given, otherwise the row's own
     * producer-name column, falling back to 'default' when it is absent or empty.
     */
    private function resolveSource(stdClass $row, LegacyCallColumns $columns, ?string $override): string
    {
        if ($override !== null) {
            return $override;
        }

        $name = $columns->of($row, $columns->source);

        return is_string($name) && $name !== '' ? $name : 'default';
    }

    /**
     * The source row's own id, as a string, because it is hashed rather than compared. A row whose
     * id column is absent or non-scalar yields '' — which still derives a stable key, so such rows
     * collapse onto one instead of throwing halfway through somebody's backlog.
     */
    private function sourceRowId(stdClass $row, LegacyCallColumns $columns): string
    {
        $id = $columns->of($row, $columns->id);

        if (is_scalar($id)) {
            return (string) $id;
        }

        return '';
    }

    /**
     * The 13 positional bindings for one imported row, in the ImportInsert column order. The body
     * is the source payload re-encoded with the same flags the live receiver uses, so the stored
     * raw_body, its SHA-256 and the queryable payload view are all one self-consistent instant.
     *
     * @return list<mixed>
     *
     * @throws JsonException when the source payload or headers are not valid JSON
     */
    private function bindings(stdClass $row, LegacyCallColumns $columns, string $source, string $id, Dialect $dialect): array
    {
        // The payload column is typically json and nullable: a null or empty payload is a bodyless
        // call, imported as an empty object rather than an error. Genuinely INVALID JSON (only
        // reachable from a text-typed source column) throws and the caller records the row as
        // unreadable.
        $payload = $columns->of($row, $columns->payload);
        $json = is_string($payload) && $payload !== '' ? $payload : '{}';
        // Moving the depth argument by one changes nothing a test should chase: reaching the
        // difference needs a payload nested 511 levels deep, and that fixture would be a test about
        // json_decode rather than about this import. 512 is PHP's own default, written out only so
        // the JSON_THROW_ON_ERROR beside it can be passed at all.
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $body = json_encode(PayloadSanitizer::scrub(is_array($decoded) ? $decoded : []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // The error column is read for its PRESENCE, not its content: it says whether handling
        // failed back then. The text itself is not carried -- this log has no column for it, and
        // inventing one would mean copying an unbounded, unredactable stack trace out of somebody
        // else's table into this one.
        $error = $columns->of($row, $columns->error);
        $status = is_string($error) && $error !== '' ? WebhookCallStatus::Failed : WebhookCallStatus::Processed;

        return [
            $id,
            $source,
            null, // webhook_id — such a table captures no producer id; dedupe rests on the derived key
            InboundMessage::fromRawBody($body)->type,
            $body,
            WebhookCall::encodeRawBody($body),
            null, // payload_disk — imports are never offloaded
            null, // payload_path
            hash('sha256', $body),
            $this->headersJson($row, $columns),
            $status->value,
            $this->timestamp($row->created_at ?? null, $dialect),
            $this->timestamp($row->updated_at ?? null, $dialect),
        ];
    }

    /**
     * The redacted-and-scrubbed headers JSON, or null when the source row stored none. The
     * credential-bearing headers (Authorization, Cookie) are masked exactly as the live receive
     * path masks them, so a backfilled row never carries a token the real path would have hidden.
     *
     * @throws JsonException when the stored headers are not valid JSON
     */
    private function headersJson(stdClass $row, LegacyCallColumns $columns): ?string
    {
        $headers = $columns->of($row, $columns->headers);

        if (! is_string($headers) || $headers === '') {
            return null;
        }

        // The depth argument is written out for the reason given at the first call site above: 512
        // is PHP's own default, spelled out only so the JSON_THROW_ON_ERROR beside it can be passed
        // at all.
        $decoded = json_decode($headers, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return null;
        }

        return json_encode(PayloadSanitizer::scrub(HeaderRedactor::mask($decoded)), JSON_THROW_ON_ERROR);
    }

    /**
     * A source timestamp rendered for the target engine, preserving the original instant. Such a
     * table stores UTC, so a naive value is read as UTC; a missing one falls back to now().
     */
    private function timestamp(mixed $value, Dialect $dialect): string
    {
        // The `!== ''` clause changes no answer: Carbon reads Date::parse('') as now, which is
        // exactly what the fallback beside it produces, so no input separates the two. The
        // `is_string()` check does not share that property — without it an integer reaches
        // Date::parse() and is turned into some instant, which is why that half is pinned by a
        // test.
        //
        // Kept rather than deleted: it states at the point of reading that an empty value has no
        // timestamp in it, instead of leaning on a convenience of the date library.
        $moment = is_string($value) && $value !== '' ? Date::parse($value, 'UTC') : Date::now();

        return Timestamp::forDialect($dialect, $moment);
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

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
