<?php

declare(strict_types=1);

namespace Webhooks\Database\Dialect\Sql;

/**
 * A COUNT of the rows matching a condition, portable across PostgreSQL and MySQL.
 *
 * Replaces `count(*) FILTER (WHERE cond)` — a PostgreSQL-only ordered-set filter — with
 * `count(case when cond then 1 end)`, which both engines evaluate identically: the CASE
 * yields 1 for a matching row and NULL otherwise, and count() ignores the NULLs. One
 * expression, no dialect branch.
 *
 * @internal
 */
final class ConditionalCount
{
    public static function of(string $condition): string
    {
        return "count(case when {$condition} then 1 end)";
    }
}
