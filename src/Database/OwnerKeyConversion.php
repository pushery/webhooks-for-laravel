<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Support\TableLookup;
use RuntimeException;

/**
 * Brings the `owner_id` columns of an installation whose tables hold no rows to another owner_key_type.
 *
 * The type is rendered at migration time from `webhooks.platform.owner_key_type`, into the subscriptions
 * table, the delivery log and the dashboard rollup: a materialized view over the delivery log on
 * PostgreSQL, a table of its own on MySQL. An installation migrated before the setting was chosen keeps
 * the columns it got. This class changes each of them to what a fresh migration with the configured type
 * creates, and only while the tables are empty: a row's key is of the old type, and no conversion
 * carries it to the new one.
 *
 * PostgreSQL does not change the type of a column a view selects, so the rollup view is dropped and
 * created again from its stored definition and indexes, in one transaction with the column changes and
 * behind a lock that keeps a row from arriving in between. MySQL commits each schema statement on its
 * own: a run that stops part way leaves columns of both types, and the next run changes the ones still
 * of the old type.
 *
 * @internal
 */
final readonly class OwnerKeyConversion
{
    /** The rollup: a materialized view over the delivery log on PostgreSQL, a table on MySQL. */
    public const string ROLLUP = 'webhook_delivery_hourly';

    /**
     * The tables whose owner_id is a column of their own, in the order they change.
     *
     * @var list<string>
     */
    private const array TABLES = ['webhook_subscriptions', 'webhook_deliveries', self::ROLLUP];

    public function __construct(private ?string $connection = null) {}

    /**
     * Each table whose owner_id is not of the given type, with the type it has as the engine spells it.
     * A table that does not exist has nothing to change, and PostgreSQL's rollup view is not a table:
     * it follows the delivery log.
     *
     * @return array<string, string>
     */
    public function pending(OwnerKeyType $target): array
    {
        $pending = [];

        foreach (self::TABLES as $table) {
            if (! TableLookup::exists($table, $this->connection)) {
                continue;
            }

            $type = $this->ownerColumnType($table);

            if ($type !== null && OwnerKeyDeclaration::typeOf($type) !== $target) {
                $pending[$table] = $type;
            }
        }

        return $pending;
    }

    /**
     * The tables the type spans that hold at least one row.
     *
     * @return list<string>
     */
    public function occupied(): array
    {
        $db = DB::connection($this->connection);

        return array_values(array_filter(
            self::TABLES,
            fn (string $table): bool => TableLookup::exists($table, $this->connection) && $db->table($table)->exists(),
        ));
    }

    /** Whether a conversion to the given type drops PostgreSQL's rollup view and creates it again. */
    public function rebuildsRollupView(OwnerKeyType $target): bool
    {
        return array_key_exists('webhook_deliveries', $this->pending($target))
            && $this->rollupView(DB::connection($this->connection)) !== null;
    }

    /**
     * Change every owner_id that is not of the given type to it.
     *
     * A table with rows ends the run before any statement: on PostgreSQL the change would set every
     * key to null, and on MySQL it would have to convert keys no conversion can turn into keys of the
     * other type.
     *
     * @throws RuntimeException when a table the type spans holds a row
     */
    public function convert(OwnerKeyType $target): void
    {
        $db = DB::connection($this->connection);

        if (Dialect::for($this->connection) === Dialect::MySql) {
            $this->refuseOccupied();
            $this->convertMySql($db, $target, array_keys($this->pending($target)));

            return;
        }

        $db->transaction(function () use ($db, $target): void {
            $this->lockPostgresTables($db);
            $this->refuseOccupied();
            $this->convertPostgres($db, $target, array_keys($this->pending($target)));
        });
    }

    private function refuseOccupied(): void
    {
        $occupied = $this->occupied();

        if ($occupied !== []) {
            throw new RuntimeException(sprintf(
                '%s %s rows, and owner_id changes its type only while the tables are empty.',
                implode(' and ', $occupied),
                count($occupied) === 1 ? 'holds' : 'hold',
            ));
        }
    }

    /** Both tables, so no row arrives between the check that they are empty and the change. */
    private function lockPostgresTables(Connection $db): void
    {
        $grammar = $db->getQueryGrammar();
        $tables = [];

        foreach (['webhook_subscriptions', 'webhook_deliveries'] as $table) {
            if (TableLookup::exists($table, $this->connection)) {
                $tables[] = $grammar->wrapTable(TableLookup::qualified($table, $this->connection));
            }
        }

        if ($tables !== []) {
            $db->statement('lock table '.implode(', ', $tables).' in access exclusive mode');
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private function convertPostgres(Connection $db, OwnerKeyType $target, array $tables): void
    {
        $view = in_array('webhook_deliveries', $tables, true) ? $this->rollupView($db) : null;

        if ($view !== null) {
            $db->statement('DROP MATERIALIZED VIEW '.$view['name']);
        }

        $type = $target->rawType(Dialect::Pgsql);

        foreach ($tables as $table) {
            // The tables are empty, so the new column starts empty as well: no key is carried over.
            $db->statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN owner_id TYPE %s USING NULL::%s',
                $db->getQueryGrammar()->wrapTable(TableLookup::qualified($table, $this->connection)),
                $type,
                $type,
            ));
        }

        if ($view !== null) {
            // The stored definition selects owner_id from the delivery log, so the view takes the new type
            // from it, with whatever the installation's own migration put in it (the t-digest column among them).
            $db->statement(sprintf('CREATE MATERIALIZED VIEW %s AS %s', $view['name'], $view['definition']));

            foreach ($view['indexes'] as $index) {
                $db->statement($index);
            }
        }
    }

    /**
     * PostgreSQL's rollup view as it stands: its schema-qualified name, the query it was created with
     * and the statement of each of its indexes. Null when there is none.
     *
     * @return array{name: string, definition: string, indexes: list<string>}|null
     */
    private function rollupView(Connection $db): ?array
    {
        if (Dialect::for($this->connection) !== Dialect::Pgsql) {
            return null;
        }

        $view = $db->selectOne(
            "select quote_ident(n.nspname) || '.' || quote_ident(c.relname) as name, pg_get_viewdef(c.oid, true) as definition, c.oid::text as oid "
            .'from pg_class c join pg_namespace n on n.oid = c.relnamespace '
            ."where c.oid = to_regclass(?) and c.relkind = 'm'",
            [self::ROLLUP],
        );

        // No row when the dashboard layer created no view.
        $fields = is_object($view) ? get_object_vars($view) : [];
        $name = $fields['name'] ?? null;
        $definition = $fields['definition'] ?? null;
        $oid = $fields['oid'] ?? null;

        if (! is_string($name) || ! is_string($definition) || ! is_string($oid)) {
            return null;
        }

        $indexes = [];

        foreach ($db->select('select pg_get_indexdef(indexrelid) as statement from pg_index where indrelid = ?::oid order by indexrelid', [$oid]) as $row) {
            $statement = is_object($row) ? (get_object_vars($row)['statement'] ?? null) : null;

            if (is_string($statement)) {
                $indexes[] = $statement;
            }
        }

        return [
            'name' => $name,
            // pg_get_viewdef() ends the query with a semicolon, which CREATE ... AS does not take.
            'definition' => rtrim(trim($definition), ';'),
            'indexes' => $indexes,
        ];
    }

    /**
     * @param  list<string>  $tables
     */
    private function convertMySql(Connection $db, OwnerKeyType $target, array $tables): void
    {
        $type = $target->rawType(Dialect::MySql);

        foreach ($tables as $table) {
            $wrapped = $db->getQueryGrammar()->wrapTable($table);

            if ($table === self::ROLLUP) {
                // As the rollup migrations render it: part of the primary key, so never null, with the
                // type's sentinel as the default, and a character key under utf8mb4_0900_as_cs.
                $db->statement(sprintf(
                    "ALTER TABLE %s MODIFY owner_id %s%s NOT NULL DEFAULT '%s'",
                    $wrapped,
                    $type,
                    $target === OwnerKeyType::Bigint ? '' : ' COLLATE utf8mb4_0900_as_cs',
                    $target->sentinelId(),
                ));

                continue;
            }

            $db->statement(sprintf('ALTER TABLE %s MODIFY owner_id %s NULL', $wrapped, $type));
        }
    }

    /** The engine's spelling of the table's owner_id type, or null when it has no such column. */
    private function ownerColumnType(string $table): ?string
    {
        $columns = Schema::connection($this->connection)->getColumns(TableLookup::qualified($table, $this->connection));

        return array_column($columns, 'type', 'name')['owner_id'] ?? null;
    }
}
