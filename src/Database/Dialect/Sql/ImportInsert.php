<?php

declare(strict_types=1);

namespace Webhooks\Database\Dialect\Sql;

use Webhooks\Database\Dialect\Dialect;

/**
 * The idempotent insert the spatie backfill command writes each historical row with. It is a
 * sibling of {@see DedupeInsert}, but for a different job, so it differs in three ways:
 *
 *  - it conflicts on the PRIMARY KEY (id), not on (source, webhook_id). An imported row carries
 *    no producer webhook_id — spatie never captured one — so the receipt-time dedupe key does not
 *    apply. Idempotency comes from a DETERMINISTIC id (uuid5 over source + the spatie row id): a
 *    second run re-derives the same id and the conflict clause skips it. A conflict on
 *    (source, webhook_id) would let the null-webhook_id rows insert again on every run.
 *  - status is BOUND, not the literal 'received'. An imported call is history, not something the
 *    receiver still has to process, so the command writes a terminal 'processed'/'failed'.
 *  - created_at/updated_at are BOUND (the spatie row's own timestamps, preserved), not now().
 *
 * Both engines take the same 13 columns in the same order as DedupeInsert; only the conflict
 * clause and the JSON cast differ. PostgreSQL RETURNs the id so the caller can count inserts
 * (null on a duplicate); MySQL reports the outcome through the affected-row count (1 inserted,
 * 0 duplicate), which is why the command reads affectingStatement().
 *
 * NEVER INSERT IGNORE on MySQL: it downgrades every error — a truncation, a bad value, a
 * deadlock — into a silently skipped row, so a real failure would masquerade as a de-duplication
 * and a customer's history would import short with no sign anything went wrong. ON DUPLICATE KEY
 * UPDATE id = id is a genuine no-op that changes nothing on a duplicate and raises every real error.
 *
 * @internal
 */
final class ImportInsert
{
    public static function spatieCalls(Dialect $dialect): string
    {
        return match ($dialect) {
            Dialect::Pgsql => <<<'SQL'
                INSERT INTO webhook_calls (id, source, webhook_id, event_type, payload, raw_body, payload_disk, payload_path, body_sha256, headers, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?::jsonb, ?, ?, ?)
                ON CONFLICT (id) DO NOTHING
                RETURNING id
                SQL,
            Dialect::MySql => <<<'SQL'
                INSERT INTO webhook_calls (id, source, webhook_id, event_type, payload, raw_body, payload_disk, payload_path, body_sha256, headers, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, CAST(? AS JSON), ?, ?, ?, ?, CAST(? AS JSON), ?, ?, ?)
                ON DUPLICATE KEY UPDATE id = id
                SQL,
        };
    }
}
