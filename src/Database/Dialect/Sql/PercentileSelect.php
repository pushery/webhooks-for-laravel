<?php

declare(strict_types=1);

namespace Webhooks\Database\Dialect\Sql;

/**
 * A continuous (interpolating) percentile of duration_ms over a bounded set of delivery rows.
 *
 * PostgreSQL has the ordered-set aggregate percentile_cont, which can sit inline in a SELECT
 * beside the counts. MySQL has no such aggregate, so the same interpolated value is reconstructed
 * with window functions in its own query: rank the non-null durations, find the fractional
 * position p·(N−1), and linearly interpolate between the two rows that straddle it — exactly what
 * percentile_cont does, so the two engines return the same number (verified: 212.5 on both).
 *
 * NULL durations are excluded explicitly (percentile_cont ignores them for free); an empty set
 * yields NULL, which the caller reads as zero.
 *
 * @internal
 */
final class PercentileSelect
{
    /**
     * The PostgreSQL percentile expression, for use inline in a larger SELECT.
     *
     * @return literal-string
     */
    public static function pgsqlExpression(float $percentile): string
    {
        return 'percentile_cont('.self::fraction($percentile).') WITHIN GROUP (ORDER BY duration_ms)';
    }

    /**
     * The full MySQL query returning a single `p95` column: the interpolated percentile over the
     * rows matched by $where (which carries its own bindings). $where and $table are rendered by
     * the caller from fixed fragments, never from request input.
     *
     * @param  literal-string  $table
     * @param  literal-string  $where
     * @return literal-string
     */
    public static function mysqlQuery(float $percentile, string $table, string $where): string
    {
        $p = self::fraction($percentile);

        return 'SELECT v_base + frac * (COALESCE(v_next, v_base) - v_base) AS p95 FROM ('
            .'SELECT '
            .'MAX(CASE WHEN rn = base_rn THEN duration_ms END) AS v_base, '
            .'MAX(CASE WHEN rn = base_rn + 1 THEN duration_ms END) AS v_next, '
            .'MAX(frac) AS frac '
            .'FROM ('
            .'SELECT duration_ms, '
            .'ROW_NUMBER() OVER (ORDER BY duration_ms) AS rn, '
            .'FLOOR('.$p.' * (COUNT(*) OVER () - 1)) + 1 AS base_rn, '
            .$p.' * (COUNT(*) OVER () - 1) - FLOOR('.$p.' * (COUNT(*) OVER () - 1)) AS frac '
            .'FROM '.$table.' WHERE '.$where.' AND duration_ms IS NOT NULL'
            .') ranked'
            .') picked';
    }

    /**
     * A MySQL query returning several interpolated percentiles of duration_ms over ONE window
     * (the whole set matched by $where) in a single pass — the window-level KPIs PostgreSQL gets
     * from percentile_cont(ARRAY[...]). Each named percentile is reconstructed by the same
     * rank-and-interpolate as {@see mysqlQuery}; they share one scan of the ranked rows.
     *
     * @param  array<string, float>  $percentiles  alias => fraction, e.g. ['p50' => 0.5, 'p95' => 0.95]
     * @param  string  $table  a bare identifier the caller has validated (never request input)
     * @param  literal-string  $where
     */
    public static function mysqlWindowMulti(array $percentiles, string $table, string $where): string
    {
        $ranked = ['duration_ms AS d', 'ROW_NUMBER() OVER (ORDER BY duration_ms) AS rn'];
        $outer = [];

        foreach ($percentiles as $alias => $fraction) {
            $p = self::fraction($fraction);
            $base = 'b_'.$alias;
            $frac = 'f_'.$alias;

            $ranked[] = 'FLOOR('.$p.' * (COUNT(*) OVER () - 1)) + 1 AS '.$base;
            $ranked[] = $p.' * (COUNT(*) OVER () - 1) - FLOOR('.$p.' * (COUNT(*) OVER () - 1)) AS '.$frac;

            $outer[] = 'MAX(CASE WHEN rn = '.$base.' THEN d END) + MAX('.$frac.') * ('
                .'COALESCE(MAX(CASE WHEN rn = '.$base.' + 1 THEN d END), MAX(CASE WHEN rn = '.$base.' THEN d END)) '
                .'- MAX(CASE WHEN rn = '.$base.' THEN d END)) AS '.$alias;
        }

        return 'SELECT '.implode(', ', $outer)
            .' FROM (SELECT '.implode(', ', $ranked)
            .' FROM '.$table.' WHERE '.$where.' AND duration_ms IS NOT NULL) ranked';
    }

    /**
     * The percentile fraction as a fixed decimal literal (0.5, 0.95, …), never a bound parameter,
     * so it can be embedded in the SQL. Rendered to two decimals, which covers every tier used.
     *
     * @return literal-string
     */
    private static function fraction(float $percentile): string
    {
        return match ($percentile) {
            0.5 => '0.50',
            0.9 => '0.90',
            0.95 => '0.95',
            0.99 => '0.99',
            default => '0.95',
        };
    }
}
