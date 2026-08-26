<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Database;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Pushery\Webhooks\Dashboard\Policies\WebhookDeliveryPolicy;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\WebhookManager;
use Throwable;

/**
 * Holds `webhooks.platform.owner_key_type` against the columns it claims to describe.
 *
 * The setting is a DECLARATION, and it has to be: it is read before the tables exist, because
 * it is what renders them. Nothing afterwards ever checks that it still matches — and three
 * places at run time believe it over the database:
 *
 *   - {@see WebhookManager} rejects any owner key the declared type cannot
 *     hold. LOUD: an InvalidArgumentException, and the registration form is dead.
 *   - {@see WebhookDelivery} casts `owner_id` by it. SILENT: under a
 *     wrongly declared `bigint`, `019fff78-…` reads back as `19` — and every UUIDv7 owner
 *     collapses onto that same `19`.
 *   - {@see WebhookDeliveryPolicy} compares that value
 *     against the tenant. FAIL-CLOSED: every tenant is refused its own deliveries, which
 *     reads like an authorization decision rather than a defect.
 *
 * ⚠️ THE CONTRADICTION IS AN EXPECTED STATE, NOT AN ABUSE. A host that partitions differently
 * or needs its own indexes FORKS the two create-table migrations — that is a normal thing to
 * do, and this package's own docs describe the shape. The moment it happens, the column comes
 * from the fork and the setting comes from the config, and nothing holds the two together.
 * The exception in the first bullet is well written and names the cure exactly; it simply
 * arrives at the first customer click, months after the mistake was made.
 *
 * So the check reads the column rather than believing the setting, and it is deliberately
 * placed where a host is already looking: `webhooks:preflight` (onboarding) and right after
 * `migrate` (the moment the schema was touched at all — including by a FORKED migration,
 * which is precisely the case a guard living inside this package's migration files could
 * never see).
 *
 * It reports and never blocks. A schema it cannot READ — a server that does not answer, a
 * table that is not there — and a type spelling it does not recognize both produce NO
 * finding: a check that guesses in the unknown direction turns into a false alarm against a
 * correct installation, and one of those costs more than the defect.
 *
 * What it does NOT swallow is a connection NAME that is not configured at all. That throws
 * here exactly as it throws everywhere else in the package, because it is not a fact about
 * the schema — it is a config key pointing at nothing, and catching it here would only make
 * one loud misconfiguration quieter in one place.
 *
 * @internal
 */
final class OwnerKeyDeclaration
{
    /**
     * The tables carrying the denormalised owner key. The rollup is MySQL-only (PostgreSQL's
     * is a view over the delivery log), and every one of them is checked only if it exists —
     * a layer a host does not run leaves nothing to contradict.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'webhook_subscriptions',
        'webhook_deliveries',
        'webhook_delivery_hourly',
    ];

    /**
     * One message per table whose `owner_id` contradicts the declared type. Empty means
     * agreement — or that nothing could be read, which is reported as agreement on purpose.
     *
     * @return list<string>
     */
    public static function contradictions(?string $connection = null): array
    {
        $declared = self::declared();

        if (! $declared instanceof OwnerKeyType) {
            // A setting that is not one of the three throws at its point of use with a
            // message of its own. Repeating that here would only give the same mistake two
            // voices, and this one is not the better of the two.
            return [];
        }

        $name = $connection ?? Config::get('webhooks.database.connection');
        // ⚠️ Both halves of this guard are reported as survivors, and both are equivalent.
        // Turning `&&` into `||` reaches Schema::connection() with the same argument it would
        // have had anyway — a non-string name is not a string, so the ternary still falls to
        // null. Moving the empty-string test needs a connection literally named '', which no
        // configuration produces. Measured, both, suite green.
        //
        // Kept: this line is what turns "unset or blank" into "the application default", and
        // saying so here is cheaper than making a reader derive it from two coercions.
        $schema = Schema::connection(is_string($name) && $name !== '' ? $name : null);

        $found = [];

        foreach (self::TABLES as $table) {
            try {
                if (! $schema->hasTable($table)) {
                    continue;
                }

                $column = self::ownerColumn($schema->getColumns($table));
            } catch (Throwable) {
                // Reading a schema is not this check's job to guarantee. A connection that
                // cannot answer is the caller's problem to report, not a contradiction.
                //
                // ⚠️ This is the ONE `continue` in this loop with no arm on it, and it is a
                // deliberate gap rather than an oversight. The other four are each pinned by a
                // "keeps checking after …" test, because turning any of them into a `break`
                // would end the scan at a skipped table and report a forked schema as sound.
                // Reaching THIS one needs a connection that answers for one table and throws
                // for the next, which is a stub of the schema builder rather than a database
                // state — a test whose subject would be the stub. Left as measured.
                continue;
            }

            if ($column === null) {
                continue;
            }

            $observed = self::classify($column);
            if (! $observed instanceof OwnerKeyType) {
                continue;
            }
            if ($observed === $declared) {
                continue;
            }

            $found[] = sprintf(
                '%s.owner_id is %s, which is a "%s" key — but webhooks.platform.owner_key_type '
                .'declares "%s". The declaration wins at run time: owner keys are validated, cast '
                .'and compared as "%s", so a key of the column\'s actual shape is either refused '
                .'outright or silently read back as a different value.',
                $table,
                $column,
                $observed->value,
                $declared->value,
                $declared->value,
            );
        }

        return $found;
    }

    private static function declared(): ?OwnerKeyType
    {
        $configured = Config::get('webhooks.platform.owner_key_type', OwnerKeyType::Bigint->value);

        if (! is_string($configured)) {
            return null;
        }

        return OwnerKeyType::tryFrom($configured);
    }

    /**
     * The engine's own spelling of the `owner_id` column's type, or null when the table has
     * no such column.
     *
     * @param  list<array<string, mixed>>  $columns
     */
    private static function ownerColumn(array $columns): ?string
    {
        foreach ($columns as $column) {
            $type = $column['type'] ?? null;

            // One condition rather than a guard per half. Both halves are reached on every
            // column of every table, and a schema that answered with a non-string type has no
            // separate outcome worth its own branch — it is the same "no answer" as a table
            // without the column, which is what the return below already says.
            // The `!== ''` half is unkillable: a column the schema reports has a type, and an
            // empty one is not a shape any driver returns (measured). Kept beside the
            // is_string() so the pair reads as one statement about a usable type.
            if (($column['name'] ?? null) === 'owner_id' && is_string($type) && $type !== '') {
                return $type;
            }
        }

        return null;
    }

    /**
     * Which of the three declarable types a column spelling actually is.
     *
     * Read from the engine's own rendering rather than a table of expected spellings, because
     * the spellings differ per engine AND per how the column was written: PostgreSQL answers
     * `bigint` / `uuid` / `character(26)`, MySQL answers `bigint unsigned` / `char(36)` /
     * `char(26)`, and a forked migration may have used any equivalent.
     *
     * ⚠️ THE ORDER IS LOAD-BEARING AND THE WIDTHS ARE THE ONLY THING SEPARATING TWO OF THEM.
     * MySQL stores a UUID as `char(36)` and a ULID as `char(26)` — the type name is identical
     * and only the width tells them apart, so the character branches must test the width and
     * must come after the two named types. Anything else answers null, and null is silence,
     * not a finding.
     */
    private static function classify(string $type): ?OwnerKeyType
    {
        $normalized = mb_strtolower($type);

        return match (true) {
            str_contains($normalized, 'uuid') => OwnerKeyType::Uuid,
            str_contains($normalized, 'int') => OwnerKeyType::Bigint,
            str_contains($normalized, '(36)') => OwnerKeyType::Uuid,
            str_contains($normalized, '(26)') => OwnerKeyType::Ulid,
            default => null,
        };
    }
}
