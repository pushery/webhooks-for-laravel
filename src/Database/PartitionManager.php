<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Database;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Support\Timestamp;
use Pushery\Webhooks\Support\WebhookConnection;
use RuntimeException;

/**
 * Creates and drops the monthly range partitions behind the webhook_deliveries
 * table. Shared by the create migration and the webhooks:partition-maintenance
 * command so the partition scheme has a single source of truth.
 *
 * Months are UTC months. The partition key is a timestamptz, so a bound is an
 * INSTANT, not a wall-clock string: anchoring the boundaries to a local calendar
 * would shift them by the local offset and — because that offset changes twice a
 * year — leave a one-hour gap or overlap between two adjacent partitions.
 *
 * The catch-all default partition is a safety net, not a resting place. A row that
 * lands in it blocks the creation of the partition that should have held it
 * ("updated partition constraint for default partition would be violated by some
 * row"), which would stop partition creation AND retention pruning dead. So the
 * manager drains it: the month's partition is built as a freestanding table, the
 * stranded rows move into it in chunks, and only then is it attached, so no DETACH
 * locks the parent while rows move. {@see self::drainDefaultPartitionInto()} states
 * the locks the attach takes and what a reader of the log sees meanwhile.
 */
final class PartitionManager
{
    private const string TABLE = 'webhook_deliveries';

    private const string DEFAULT_PARTITION = self::TABLE.'_default';

    /**
     * Rows moved out of the default partition per transaction.
     *
     * The drain runs after days or weeks of stranded deliveries, so the total is unbounded by
     * nature; what this bounds is how long any ONE transaction holds its locks and how much a
     * failure has to redo. A chunk that fails leaves its rows in the default, which is the state
     * the next run already starts from.
     */
    private const int DRAIN_CHUNK = 5000;

    /**
     * How long a schema statement on the delivery log waits for its lock before it gives up, as a
     * PostgreSQL interval.
     *
     * Creating, attaching and dropping a partition take locks that conflict with an open read of
     * the log, and a statement waiting for such a lock makes every later read and write of the
     * log wait behind it, not only itself. Bounded, the statement gives up instead and the log's
     * traffic waits at most this long; webhooks:partition-maintenance then fails with PostgreSQL's
     * lock timeout, and its next run tries again. The window is provisioned months ahead, and a
     * drain that gave up before its ATTACH is resumed, so one run that gave up costs nothing.
     */
    public const string LOCK_TIMEOUT = '2s';

    private function db(): ConnectionInterface
    {
        return WebhookConnection::db();
    }

    /**
     * Run one schema statement with {@see self::LOCK_TIMEOUT}, in a transaction of its own, so the
     * setting ends with the statement, also when the statement gives up.
     */
    private function ddl(string $sql): void
    {
        $this->db()->transaction(function () use ($sql): void {
            $this->db()->statement("set local lock_timeout = '".self::LOCK_TIMEOUT."'");
            $this->db()->statement($sql);
        });
    }

    /**
     * A column name read from the catalog, quoted for PostgreSQL. The names are read from
     * pg_attribute so that the drain carries every column, including one a host's own migration
     * added, and such a column may be a reserved word or carry capitals; unquoted it breaks the
     * statement, and the maintenance run with it, every day it is retried.
     */
    private static function quoteIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    public function partitionName(CarbonInterface $month): string
    {
        return self::TABLE.'_'.$this->monthStart($month)->format('Y_m');
    }

    /**
     * Ensure the partition for one month exists, draining any rows the default
     * partition is already holding for it. Returns the partition name.
     */
    public function ensureMonthlyPartition(CarbonInterface $month): string
    {
        $start = $this->monthStart($month);
        $end = $start->addMonth();
        $name = $this->partitionName($start);

        // The question is whether it is a partition, not whether a table of that name exists, and
        // the difference is the whole failure mode. The drain below creates its target freestanding
        // and attaches it at the end, so anything that interrupts it between those two steps (a
        // killed command, an OOM, a deploy restart, a chunk that threw) leaves a table with exactly
        // this name that is nobody's partition. Asked by name, every later run would see it, return
        // early and report success, while the month's rows kept landing in the default and the
        // partition was never attached.
        if ($this->partitionExists($name)) {
            return $name;
        }

        // A leftover of that kind is resumed rather than worked around: the drain is written to
        // be re-enterable, so it moves whatever is still in the default and performs the ATTACH
        // that did not happen. The second condition is the ordinary case — rows the default is
        // holding for a month whose partition was never created at all. The third is the same
        // case, seen only by the count the CREATE makes under its own lock.
        if ($this->tableExists($name) || $this->defaultPartitionCount($start, $end) > 0 || ! $this->createPartition($name, $start, $end)) {
            $this->drainDefaultPartitionInto($name, $start, $end);
        }

        return $name;
    }

    /**
     * Create the month's partition, unless the default holds rows of its range; then nothing is
     * created, and false leaves those rows to the drain.
     *
     * The count and the CREATE share one transaction, which first takes the lock the CREATE needs
     * anyway, ACCESS EXCLUSIVE on the parent. A write to the log waits for it before its row is
     * routed, so no delivery of the month can reach the default between the count and the CREATE.
     * Counted apart, one could, and the CREATE would then refuse the month ("updated partition
     * constraint for default partition would be violated by some row").
     */
    private function createPartition(string $name, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->db()->transaction(function () use ($name, $start, $end): bool {
            $this->db()->statement("set local lock_timeout = '".self::LOCK_TIMEOUT."'");
            $this->db()->statement(sprintf('lock table only %s in access exclusive mode', self::TABLE));

            if ($this->defaultPartitionCount($start, $end) > 0) {
                return false;
            }

            $this->db()->statement(sprintf(
                'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
                $name,
                self::TABLE,
                $this->quoteTimestamp($start),
                $this->quoteTimestamp($end),
            ));

            return true;
        });
    }

    /**
     * The delivery a drain has moved into a target it has not attached yet, or null.
     *
     * Until the ATTACH, a moved row sits in a table that is not part of the log, so a lookup on
     * the parent cannot see it: for the length of the drain, and for good when a drain died
     * before its ATTACH and no run has resumed it yet. A caller that found nothing on the parent
     * asks here before it treats the delivery as gone. Written back through the returned model,
     * an update lands in that table, which is the month's partition after the ATTACH.
     *
     * Read without global scopes, because the question is where the row is, not who may see it.
     * Null on MySQL, where the log has no partitions.
     */
    public function movedDelivery(int|string $id, string $createdAt): ?WebhookDelivery
    {
        $dialect = WebhookConnection::dialect();
        $name = $this->partitionName(Timestamp::read($dialect, $createdAt));

        if ($dialect !== Dialect::Pgsql || $this->partitionExists($name) || ! $this->tableExists($name)) {
            return null;
        }

        $model = WebhookDelivery::resolve();
        $model->setTable($name);

        return $model->newQuery()->withoutGlobalScopes()->whereKey($id)->where('created_at', $createdAt)->first();
    }

    public function ensureWindow(CarbonInterface $from, int $months): void
    {
        $cursor = $this->monthStart($from);

        for ($i = 0; $i < $months; $i++) {
            $this->ensureMonthlyPartition($cursor);
            $cursor = $cursor->addMonth();
        }
    }

    public function ensureDefaultPartition(): void
    {
        $this->ddl(sprintf(
            'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s DEFAULT',
            self::DEFAULT_PARTITION,
            self::TABLE,
        ));
    }

    /**
     * Give every month currently stranded in the default partition its own monthly
     * partition, moving its rows across. This is the self-heal: a gap in the schedule
     * (a paused worker, a forgotten cron, a long deploy freeze) lets deliveries land in
     * the default, and from then on nothing else can be provisioned until they are
     * drained. Returns the months that were drained, oldest first.
     *
     * @return list<string> the drained partition names
     */
    public function drainDefaultPartition(): array
    {
        $drained = [];

        foreach ($this->defaultPartitionMonths() as $month) {
            $drained[] = $this->ensureMonthlyPartition($month);
        }

        return $drained;
    }

    /**
     * How many rows sit in the default partition — the drift signal an operator needs:
     * anything above zero means deliveries are landing outside the provisioned window.
     * Bounded to one month when a range is given.
     */
    public function defaultPartitionCount(?CarbonInterface $from = null, ?CarbonInterface $to = null): int
    {
        if (! $this->tableExists(self::DEFAULT_PARTITION)) {
            return 0;
        }

        $sql = 'SELECT count(*) AS total FROM '.self::DEFAULT_PARTITION;
        $bindings = [];

        if ($from instanceof CarbonInterface && $to instanceof CarbonInterface) {
            $sql .= ' WHERE created_at >= ? AND created_at < ?';
            $bindings = [Timestamp::sql($from), Timestamp::sql($to)];
        }

        $total = data_get($this->db()->selectOne($sql, $bindings), 'total');

        if (is_numeric($total)) {
            return (int) $total;
        }

        return 0;
    }

    /**
     * The names of the monthly partitions (webhook_deliveries_YYYY_MM), excluding
     * the catch-all default partition.
     *
     * @return list<string>
     */
    public function monthlyPartitions(): array
    {
        // Anchored on the parent's oid rather than on its name, for the same reason as
        // insertableColumns() below. `p.relname = ?` matches a table of that name in every schema
        // of the database, so a second install, a tenant-per-schema layout or a
        // staging schema in the same database would make this return partitions belonging to
        // somebody else's parent. `to_regclass` resolves exactly one relation through the
        // search_path, and asking for ITS children cannot answer about another lineage.
        //
        // The cost of that would be a wrong DROP: a foreign partition in this list would make
        // dropPartitionsOlderThan() drop the CURRENT schema's same-named table instead — see the
        // note there.
        return array_keys($this->partitionIdentifiers());
    }

    /**
     * Every monthly partition of THIS parent, as `bare name => identifier to address it by`.
     *
     * Two values because two different questions are asked of the same relation, and answering
     * both from one row is what keeps them about the same object. The bare name is what the
     * YYYY_MM comparison and every caller reads; the identifier is what a statement must name.
     *
     * `oid::regclass` rather than a hand-built `schema.name`: it renders the schema only when
     * the relation is not reachable unqualified, so the ordinary single-schema install still
     * sees a bare name — and it quotes an identifier that needs quoting, which string
     * concatenation would not.
     *
     * @return array<string, string>
     */
    private function partitionIdentifiers(): array
    {
        $rows = $this->db()->select(<<<'SQL'
            SELECT c.relname AS name, c.oid::regclass::text AS qualified
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhrelid
            WHERE i.inhparent = to_regclass(?)
            ORDER BY c.relname
            SQL, [self::TABLE]);

        $found = [];

        foreach ($rows as $row) {
            $name = data_get($row, 'name');
            $qualified = data_get($row, 'qualified');

            if (is_string($name) && is_string($qualified)
                && preg_match('/^'.self::TABLE.'_\d{4}_\d{2}$/', $name) === 1) {
                $found[$name] = $qualified;
            }
        }

        return $found;
    }

    /**
     * Drop every monthly partition whose month begins before the given month.
     * The zero-padded YYYY_MM suffix compares chronologically as a plain string.
     *
     * @return list<string> the dropped partition names
     */
    public function dropPartitionsOlderThan(CarbonInterface $before): array
    {
        $cutoff = $this->monthStart($before)->format('Y_m');
        $prefixLength = strlen(self::TABLE) + 1;
        $dropped = [];

        foreach ($this->partitionIdentifiers() as $name => $qualified) {
            if (substr($name, $prefixLength) < $cutoff) {
                // The qualified identifier, not the bare name. A bare name in DROP resolves through
                // the search_path, so it names the current schema, while the partition may live in
                // another. Addressed by its bare name, the prune would delete an unrelated table of
                // that name in the current schema, leave the partition it meant to drop in place,
                // and report the drop as done. `oid::regclass` renders the schema only when the
                // relation is not reachable unqualified, so this is the bare name in the ordinary
                // install.
                $this->ddl('DROP TABLE IF EXISTS '.$qualified);
                $dropped[] = $name;
            }
        }

        return $dropped;
    }

    /**
     * The distinct UTC months for which the default partition is currently holding
     * rows, oldest first.
     *
     * @return list<CarbonImmutable>
     */
    private function defaultPartitionMonths(): array
    {
        if (! $this->tableExists(self::DEFAULT_PARTITION)) {
            return [];
        }

        $rows = $this->db()->select(
            'SELECT DISTINCT date_trunc(\'month\', created_at AT TIME ZONE \'UTC\') AS month '
            .'FROM '.self::DEFAULT_PARTITION.' ORDER BY month'
        );

        $months = [];

        foreach ($rows as $row) {
            $month = data_get($row, 'month');

            if (is_string($month)) {
                $months[] = CarbonImmutable::parse($month, 'UTC');
            }
        }

        return $months;
    }

    /**
     * Move one month's stranded rows out of the default partition and into their own.
     *
     * The point of this shape is that the parent is not locked while the rows move. The obvious
     * drain — DETACH the default, CREATE the partition, INSERT ... SELECT through the parent,
     * DELETE, re-ATTACH, all in one transaction — is what stood here, and the non-concurrent DETACH
     * takes ACCESS EXCLUSIVE on the parent and holds it to commit. So every insert into
     * webhook_deliveries blocked for the whole move: the entire outbound delivery path, stopped by
     * a daily unattended cron.
     *
     * And the row count is largest exactly when the drain is needed. This heals a paused worker,
     * a forgotten cron, a deploy freeze — days or weeks of stranded rows — so the worst case is
     * not the rare one, it is the normal one.
     *
     * The order here avoids the DETACH entirely. The reason the old shape needed it is that a
     * partitioned table refuses to create a partition whose range the default still holds rows
     * for; so the target is built as a FREESTANDING table first, the rows are moved into it while
     * it is still nobody's partition, and only then is it attached. Moving rows out of the default
     * takes ROW EXCLUSIVE on the default alone — inserts into every other partition run
     * untouched.
     *
     * Rows keep arriving while the drain runs when the month being drained is the current one, and
     * the ATTACH alone takes only ShareUpdateExclusiveLock on the parent, which writes pass. With
     * the ATTACH in a transaction of its own, two things would fail under that traffic. A write of
     * the drained month would be routed to the default, wait there for the ATTACH, and be refused
     * by the default's new bound once the ATTACH commits ("new row for relation
     * webhook_deliveries_default violates partition constraint"), so the delivery would fail in
     * the host's request. And a row that reaches the default after the last chunk would fail the
     * ATTACH itself ("updated partition constraint for default partition would be violated by
     * some row").
     *
     * So the rows that arrived during the loop and the ATTACH share one transaction, which first
     * takes ShareRowExclusiveLock on the parent. Every INSERT, UPDATE and DELETE through the parent
     * waits for that lock before its row is routed, so no row reaches the default while the
     * transaction runs, and a write that waited is routed after the ATTACH, into the month's
     * partition. The locks held while that transaction is open:
     *
     *   the parent          ShareRowExclusiveLock, and the ATTACH's ShareUpdateExclusiveLock
     *   the new partition   AccessExclusiveLock
     *   the default         AccessExclusiveLock, because attaching a range next to a default makes
     *                       PostgreSQL prove that the default holds no row of it
     *
     * Reads go on, except one that reaches the default, which waits for the ATTACH. Writes pause
     * for the last chunk and the ATTACH, on PostgreSQL 18 about 42 ms after a drain of 200,000
     * rows and 235 ms after one of 1,000,000, most of it the last chunk reading past the rows the
     * drain has just deleted. A wait
     * for one of these locks adds at most {@see self::LOCK_TIMEOUT} before the run gives up.
     *
     * The CHECK constraint is not decoration: with it, PostgreSQL can prove the target's rows all
     * belong to the range and skips scanning it during the ATTACH. It is dropped afterwards
     * because the partition bound now says the same thing, and two statements of one rule drift.
     *
     * The move is chunked so a month of stranded rows is not one transaction either. Each chunk
     * is atomic on its own, and a chunk that fails leaves the rest in the default — which is the
     * state the next run is written to handle, because it is the state it started from.
     *
     * The price of the freestanding target is visibility. A moved row is in no partition until the
     * ATTACH, so a query on the parent does not return it, for the length of the drain and, when a
     * drain dies before its ATTACH, until the next run resumes it. The two lookups that must find
     * a live delivery ask {@see self::movedDelivery()} when the parent has nothing: the lifecycle
     * subscriber, whose status writes would otherwise be dropped, and the delivery gate, which
     * would otherwise refuse the send as erased. A listing of the log does not, so the moved rows
     * reappear there with the ATTACH.
     *
     * Generated columns are excluded from the column list: PostgreSQL refuses an explicit value
     * for one, so the moved rows recompute theirs on insert.
     */
    private function drainDefaultPartitionInto(string $name, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $columns = implode(', ', array_map(self::quoteIdentifier(...), $this->insertableColumns()));
        $from = Timestamp::sql($start);
        $to = Timestamp::sql($end);
        $lower = $this->quoteTimestamp($start);
        $upper = $this->quoteTimestamp($end);

        // Freestanding, not PARTITION OF: creating it as a partition is the step the default's
        // rows would block, and it is also the step that takes the parent's lock.
        $this->ddl(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (LIKE %s INCLUDING DEFAULTS INCLUDING GENERATED INCLUDING CONSTRAINTS INCLUDING INDEXES)',
            $name,
            self::TABLE,
        ));

        // The foreign keys are carried onto the target, because `LIKE … INCLUDING CONSTRAINTS` does
        // not do it: in PostgreSQL that copies CHECK and NOT NULL and leaves foreign keys behind.
        // The parent carries `webhook_deliveries_subscription_id_foreign`, and the LIKE copy
        // carries no foreign key at all.
        //
        // Without them the moved rows sit OUTSIDE the key for the length of the drain, and the
        // key is ON DELETE CASCADE. A tenant deleting an endpoint in that window is an ordinary
        // thing to do; the cascade cannot reach these rows, because they are no longer in the
        // partitioned table. What follows is not an orphan but a failed ATTACH:
        //
        //   row survives the cascade in the freestanding table
        //   ALTER TABLE … ATTACH PARTITION
        //     ERROR: insert or update violates foreign key constraint
        //
        // So the ATTACH fails, `webhooks:partition-maintenance` throws, and it throws again on
        // every later run because the target and its unresolvable row persist: the permanent,
        // self-perpetuating outage the drain exists to prevent.
        //
        // With the key present the cascade reaches the rows while they are here, and the ATTACH
        // then succeeds — and PostgreSQL ADOPTS the constraint as the inherited one
        // (`conparentid <> 0`), exactly as it does for a partition created the ordinary way. The
        // only difference is its name, so nothing is dropped afterwards.
        foreach ($this->parentForeignKeys() as $index => $key) {
            $constraint = sprintf('%s_fk_%d', $name, $index);

            // Same reason as the range check below: no IF NOT EXISTS, and this method reruns.
            $this->ddl(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', $name, $constraint));

            // A leftover target from a run that died before this existed can hold a row whose
            // owner is gone, and ADD CONSTRAINT would validate and fail on it — the same wedge,
            // one statement earlier.
            //
            // Removing those rows is not data loss for the key this table carries, which is ON
            // DELETE CASCADE: the parent would have removed them itself had they been in it. That
            // is a statement about today's schema, not about every key this loop can meet. A
            // RESTRICT or SET NULL key would have kept the row, and deleting it here would silently
            // do what the key forbids. The reader is deliberately general, so this is the
            // assumption to revisit the day a second key appears, and the composite arm in
            // DefaultPartitionDrainTest is where that day is noticed.
            // The whole TUPLE, not one column at a time: for a composite key a row can have a
            // resolvable first column and an unresolvable pair, and a per-column check would
            // keep it and then fail the ADD CONSTRAINT below.
            $notNull = implode(' AND ', array_map(
                static fn (string $column): string => sprintf('t.%s IS NOT NULL', self::quoteIdentifier($column)),
                $key['columns'],
            ));

            $match = implode(' AND ', array_map(
                static fn (int $i): string => sprintf(
                    'r.%s = t.%s',
                    self::quoteIdentifier($key['referenced_columns'][$i]),
                    self::quoteIdentifier($key['columns'][$i]),
                ),
                array_keys($key['columns']),
            ));

            $this->db()->statement(sprintf(
                'DELETE FROM %s t WHERE %s AND NOT EXISTS (SELECT 1 FROM %s r WHERE %s)',
                $name,
                $notNull,
                $key['references'],
                $match,
            ));

            $this->ddl(sprintf('ALTER TABLE %s ADD CONSTRAINT %s %s', $name, $constraint, $key['definition']));
        }

        // Dropped first, because ADD CONSTRAINT has no IF NOT EXISTS and this method has to be
        // re-enterable: a drain that died after this line would otherwise fail here for ever on
        // every retry, which would turn a recoverable interruption into a permanent one.
        $this->ddl(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s_range', $name, $name));

        $this->ddl(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s_range CHECK (created_at >= %s AND created_at < %s)',
            $name,
            $name,
            $lower,
            $upper,
        ));

        $this->moveStrandedRows($name, $columns, $from, $to);

        // The rows that landed while the loop ran, then the ATTACH, with the log's writes stopped:
        // the paragraph on locks above says why both share this transaction.
        $this->db()->transaction(function () use ($name, $columns, $from, $to, $lower, $upper): void {
            $this->db()->statement("set local lock_timeout = '".self::LOCK_TIMEOUT."'");
            $this->db()->statement(sprintf('lock table only %s in share row exclusive mode', self::TABLE));

            $this->moveStrandedRows($name, $columns, $from, $to);

            $this->db()->statement(sprintf(
                'ALTER TABLE %s ATTACH PARTITION %s FOR VALUES FROM (%s) TO (%s)',
                self::TABLE,
                $name,
                $lower,
                $upper,
            ));
        });

        $this->ddl(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s_range', $name, $name));
    }

    /**
     * Move the default's rows of one month into the drain's target until a chunk finds none.
     */
    private function moveStrandedRows(string $name, string $columns, string $from, string $to): void
    {
        // One chunk per transaction. ctid is the cheapest stable handle for "some rows of this
        // partition" — there is no other bound available here, and ordering by a key would cost
        // a sort over the very rows being deleted.
        do {
            $moved = $this->db()->transaction(fn (): int => $this->db()->affectingStatement(
                sprintf(
                    'WITH moved AS (DELETE FROM %s WHERE ctid IN ('
                    .'SELECT ctid FROM %s WHERE created_at >= ? AND created_at < ? LIMIT %d'
                    .') RETURNING %s) INSERT INTO %s (%s) SELECT %s FROM moved',
                    self::DEFAULT_PARTITION,
                    self::DEFAULT_PARTITION,
                    self::DRAIN_CHUNK,
                    $columns,
                    $name,
                    $columns,
                    $columns,
                ),
                [$from, $to],
            ));
            // The bound cannot be read wrongly: the DELETE takes at most DRAIN_CHUNK rows, so a
            // batch that moves fewer than that has emptied the range it could see -- and a batch
            // of exactly one is such a batch. Raising the comparison ends the loop one turn
            // earlier, and a row that lands after it is the second call's, which runs with the
            // log's writes stopped.
        } while ($moved > 0);
    }

    /**
     * The parent's foreign keys as PostgreSQL states them, with the parts needed to clear a row
     * the key can no longer resolve.
     *
     * Read out of the catalog rather than repeated from the migration: a second foreign key
     * added later is carried the day it exists, and there is no second spelling of the first one
     * to drift from it.
     *
     * @return list<array{definition: string, references: string, columns: non-empty-list<string>, referenced_columns: non-empty-list<string>}>
     */
    private function parentForeignKeys(): array
    {
        $rows = $this->db()->select(
            'select c.conname as constraint_name,
                    pg_get_constraintdef(c.oid) as definition,
                    a.attname as column_name,
                    c.confrelid::regclass::text as referenced_table,
                    fa.attname as referenced_column
             from pg_constraint c
             join lateral unnest(c.conkey) with ordinality as k(attnum, ord) on true
             join pg_attribute a on a.attrelid = c.conrelid and a.attnum = k.attnum
             join lateral unnest(c.confkey) with ordinality as fk(attnum, ord) on fk.ord = k.ord
             join pg_attribute fa on fa.attrelid = c.confrelid and fa.attnum = fk.attnum
             where c.conrelid = to_regclass(?) and c.contype = \'f\'
             order by c.conname, k.ord',
            [self::TABLE],
        );

        // One row per column, not per key, and the difference only shows on a composite one.
        // `unnest(conkey)` yields a row for every column the key spans, all carrying the same
        // constraint name and the same definition. Consuming that list flat would add the same key
        // once per column, and worse, it would clear orphans column by column: a row whose (a, b)
        // pair does not exist while its `a` alone does would survive every single-column check and
        // then fail the ADD CONSTRAINT, which is the wedge this method exists to prevent, reached
        // by the path that repairs it.
        //
        // The shipped table carries one single-column key today, so the flat form would behave
        // identically. It is grouped anyway because the docblock above promises that a key added
        // later is carried the day it exists, and a promise nothing checks is the half that rots.
        $grouped = [];

        foreach ($rows as $row) {
            $constraint = data_get($row, 'constraint_name');
            $definition = data_get($row, 'definition');
            $column = data_get($row, 'column_name');
            $referenced = data_get($row, 'referenced_table');
            $referencedColumn = data_get($row, 'referenced_column');

            // Guarded as ONE condition rather than an early `continue`, which is the shape
            // insertableColumns() above uses for the same reason: the catalog always yields five
            // strings for an `f` constraint, so the other side is a line no run can enter — and
            // this file's floor is 100%, where such a line is a permanent red.
            if (is_string($constraint) && is_string($definition) && is_string($column)
                && is_string($referenced) && is_string($referencedColumn)) {
                $grouped[$constraint]['definition'] = $definition;
                $grouped[$constraint]['references'] = $referenced;
                $grouped[$constraint]['columns'][] = $column;
                $grouped[$constraint]['referenced_columns'][] = $referencedColumn;
            }
        }

        return array_values($grouped);
    }

    /**
     * The table's real (non-generated) columns, in declaration order. A generated
     * column may not be written explicitly, so it is left out of the drain's INSERT.
     *
     * Resolved through to_regclass, the same way tableExists() resolves the table, and that
     * symmetry is the point. This used to filter `information_schema.columns` on `table_schema =
     * current_schema()`, which is the first schema of the search_path, while to_regclass searches
     * the whole path. On a host whose tables sit in a schema that is not leading, the existence
     * check found the table and this returned nothing, and the empty list went straight into
     * `INSERT INTO webhook_deliveries () SELECT FROM …`: a syntax error pointing at an empty column
     * list instead of at the schema resolution behind it.
     *
     * Reading pg_attribute off the same OID makes the two questions physically the same object, so
     * they cannot answer about different tables however the search_path is ordered.
     *
     * @return list<string>
     */
    private function insertableColumns(): array
    {
        $rows = $this->db()->select(
            'SELECT a.attname AS column_name FROM pg_attribute a '
            .'WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 '
            ."AND NOT a.attisdropped AND a.attgenerated = '' "
            .'ORDER BY a.attnum',
            [self::TABLE],
        );

        $columns = [];

        foreach ($rows as $row) {
            $column = data_get($row, 'column_name');

            if (is_string($column)) {
                $columns[] = $column;
            }
        }

        // An empty list is not a drain with no columns, it is a resolution that failed — and
        // interpolated it produces SQL whose error message describes the symptom and hides the
        // cause. Saying so here costs one branch and saves the reader the search.
        if ($columns === []) {
            $path = data_get($this->db()->selectOne('SHOW search_path'), 'search_path');

            throw new RuntimeException(sprintf(
                'Cannot drain the default partition: no columns resolved for [%s]. The table is '
                .'not reachable through the current search_path (%s) on this connection.',
                self::TABLE,
                is_string($path) ? $path : 'unknown',
            ));
        }

        return $columns;
    }

    private function tableExists(string $name): bool
    {
        return data_get($this->db()->selectOne('SELECT to_regclass(?) AS oid', [$name]), 'oid') !== null;
    }

    /**
     * Whether a relation of this name is an ATTACHED partition of this package's parent table.
     *
     * Both sides resolve through `to_regclass`, so the question is about two specific OIDs and
     * not about two names that happen to match — the same reason insertableColumns() reads
     * pg_attribute off an OID rather than filtering information_schema by schema name.
     */
    private function partitionExists(string $name): bool
    {
        return data_get($this->db()->selectOne(
            'SELECT 1 AS ok FROM pg_inherits WHERE inhrelid = to_regclass(?) AND inhparent = to_regclass(?)',
            [$name, self::TABLE],
        ), 'ok') !== null;
    }

    /**
     * The UTC month a moment falls in, as an instant.
     */
    private function monthStart(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment)->setTimezone('UTC')->startOfMonth();
    }

    private function quoteTimestamp(CarbonInterface $moment): string
    {
        return "'".Timestamp::sql($moment)."'";
    }
}
