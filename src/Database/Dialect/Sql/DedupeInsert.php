<?php

declare(strict_types=1);

namespace Webhooks\Database\Dialect\Sql;

use Webhooks\Database\Dialect\Dialect;

/**
 * The idempotent insert for the inbound webhook_calls log: store the row unless one with the
 * same (source, webhook_id) already exists. Both engines take the same columns in the same
 * order; only the conflict clause, the JSON cast and the timestamp source differ.
 *
 * PostgreSQL uses the partial-unique ON CONFLICT target and RETURNING, so the caller reads the
 * returned id (null when a duplicate lost the race). MySQL uses ON DUPLICATE KEY UPDATE id = id
 * — a genuine no-op that changes nothing on a duplicate — and reports the outcome through the
 * affected-row count: 1 when the row was inserted, 0 when it was a duplicate (this is why
 * DatabaseRequirement forbids MYSQL_ATTR_FOUND_ROWS, which would report 1 either way). Its
 * created_at/updated_at are bound from PHP rather than the session-zone-dependent NOW().
 *
 * NEVER INSERT IGNORE on MySQL: it downgrades every error — a truncation, a bad foreign key, a
 * deadlock — into a silently skipped row, so a real failure would look like a de-duplication.
 *
 * @internal
 */
final class DedupeInsert
{
    public static function webhookCalls(Dialect $dialect): string
    {
        return match ($dialect) {
            Dialect::Pgsql => <<<'SQL'
                INSERT INTO webhook_calls (id, source, webhook_id, event_type, payload, raw_body, payload_disk, payload_path, body_sha256, headers, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?::jsonb, 'received', now(), now())
                ON CONFLICT (source, webhook_id) WHERE webhook_id IS NOT NULL DO NOTHING
                RETURNING id
                SQL,
            Dialect::MySql => <<<'SQL'
                INSERT INTO webhook_calls (id, source, webhook_id, event_type, payload, raw_body, payload_disk, payload_path, body_sha256, headers, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, CAST(? AS JSON), ?, ?, ?, ?, CAST(? AS JSON), 'received', ?, ?)
                ON DUPLICATE KEY UPDATE id = id
                SQL,
        };
    }
}
