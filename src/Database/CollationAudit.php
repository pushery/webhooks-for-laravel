<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Database;

use Illuminate\Database\Connection;

/**
 * Whether MySQL is still comparing the package's identity columns the way the schema declared.
 *
 * The shipped MySQL schema puts `utf8mb4_0900_as_cs` — case- AND accent-sensitive — on every
 * column that decides whether two rows are the same row. That is not a preference. Under a
 * case-insensitive collation `evt_AbC` and `evt_abc` collapse into ONE dedupe row, so a second,
 * genuinely distinct delivery is answered 200 and dropped: signature verified, never processed,
 * nothing logged as an error.
 *
 * The documentation named `webhooks:preflight` as one of the two things guarding that, and the
 * command read no collation anywhere. An operator following the page's own advice after a
 * restore, a server move or a DBA's ALTER got "Database preflight passed" over a schema that had
 * silently lost the property.
 *
 * The column set is derived from the live schema rather than listed here, and that is the point.
 * A hand-written list is exactly what let one column slip: the columns that matter are the ones a
 * UNIQUE index is built on, because that is where collation stops being cosmetic and starts
 * deciding identity. Asking information_schema which columns those are means a new unique index
 * is covered the day it is added, and a column dropped from one stops being checked without
 * anybody remembering to edit a list.
 *
 * @internal
 */
final class CollationAudit
{
    /**
     * The tables this package owns. Held against the shipped migrations by a test rather than
     * trusted — this is the one hand-written part left, so it is the one part that can rot.
     *
     * @var list<string>
     */
    public const array TABLES = [
        'webhook_deliveries',
        'webhook_calls',
        'webhook_server_deliveries',
        'webhook_subscriptions',
        'webhook_delivery_hourly',
    ];

    /**
     * One line per identity column whose collation no longer distinguishes case, accents or
     * trailing spaces.
     *
     * Empty on any engine but MySQL: PostgreSQL's default collation is deterministic, and
     * MariaDB is refused outright before a preflight gets this far.
     *
     * @return list<string>
     */
    public static function faults(Connection $connection): array
    {
        if ($connection->getDriverName() !== 'mysql') {
            return [];
        }

        // The start index is not a choice being made here: implode() reads values and ignores
        // keys, so every starting key produces the same list of placeholders.
        $placeholders = implode(', ', array_fill(0, count(self::TABLES), '?'));

        /** @var list<object{table_name: string, column_name: string, collation_name: string, index_name: string, pad_attribute: string|null}> $rows */
        $rows = $connection->select(
            <<<SQL
                SELECT DISTINCT s.TABLE_NAME AS table_name, s.COLUMN_NAME AS column_name,
                       c.COLLATION_NAME AS collation_name, s.INDEX_NAME AS index_name,
                       k.PAD_ATTRIBUTE AS pad_attribute
                FROM information_schema.STATISTICS s
                JOIN information_schema.COLUMNS c
                  ON c.TABLE_SCHEMA = s.TABLE_SCHEMA
                 AND c.TABLE_NAME = s.TABLE_NAME
                 AND c.COLUMN_NAME = s.COLUMN_NAME
                LEFT JOIN information_schema.COLLATIONS k
                  ON k.COLLATION_NAME = c.COLLATION_NAME
                WHERE s.TABLE_SCHEMA = DATABASE()
                  AND s.NON_UNIQUE = 0
                  AND s.TABLE_NAME IN ({$placeholders})
                  AND c.COLLATION_NAME IS NOT NULL
                ORDER BY s.TABLE_NAME, s.COLUMN_NAME
            SQL,
            self::TABLES,
        );

        $faults = [];

        foreach ($rows as $row) {
            $loss = self::loss($row->collation_name, $row->pad_attribute);

            if ($loss === null) {
                continue;
            }

            $faults[] = sprintf(
                'Column %s.%s is part of the unique index [%s] but collates as [%s], which %s. '
                .'Two identifiers that differ only %s then compare EQUAL, so '
                .'a distinct row is treated as a duplicate: it is accepted, answered 200 and '
                .'dropped, with nothing logged. The shipped schema declares utf8mb4_0900_as_cs '
                .'here; restore it with ALTER TABLE %s MODIFY %s ... COLLATE utf8mb4_0900_as_cs.',
                $row->table_name,
                $row->column_name,
                $row->index_name,
                $row->collation_name,
                $loss[0],
                $loss[1],
                $row->table_name,
                $row->column_name,
            );
        }

        return $faults;
    }

    /**
     * What a collation no longer tells two identifiers apart by, as the two phrases a fault
     * names it with, or null while it tells them apart as the shipped schema does.
     *
     * Case and accent are matched on the SUFFIX rather than against a list of collation names:
     * MySQL ships dozens, a host may pick a different character set, and `_as_cs` / `_bin` is the
     * property the schema depends on. Padding is read from the server. A PAD SPACE collation
     * compares `evt_1` and `evt_1 ` as equal, and the older binary collations such as
     * `utf8mb4_bin` pad, while `utf8mb4_0900_bin`, like the shipped `utf8mb4_0900_as_cs`, does
     * not.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function loss(string $collation, ?string $pad): ?array
    {
        if (! str_ends_with($collation, '_as_cs') && ! str_ends_with($collation, '_bin')) {
            return ['ignores case and accents', 'in case'];
        }

        if ($pad === 'PAD SPACE') {
            return ['pads with spaces', 'in trailing spaces'];
        }

        return null;
    }
}
