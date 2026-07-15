<?php

declare(strict_types=1);

namespace Webhooks\Database\Dialect\Sql;

use Webhooks\Database\Dialect\Dialect;

/**
 * An ORDER BY term that sorts NULLs LAST in either direction, on both engines.
 *
 * PostgreSQL says `col NULLS LAST` outright. MySQL has no such clause and sorts NULLs first on
 * ASC, so a leading `col IS NULL` term (0 for a value, 1 for NULL) pushes them to the end whichever
 * way the value column then sorts. Both keep the never-scored endpoints off the top of the health
 * board — the exact confusion the clause exists to prevent.
 *
 * @internal
 */
final class NullsLastOrder
{
    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function by(Dialect $dialect, string $column, bool $ascending): string
    {
        $direction = $ascending ? 'asc' : 'desc';

        return match ($dialect) {
            Dialect::Pgsql => $column.' '.$direction.' nulls last',
            Dialect::MySql => $column.' is null, '.$column.' '.$direction,
        };
    }
}
