<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Console;

use stdClass;

/**
 * Which columns of a pre-existing inbound-webhook table hold the five things an import needs.
 *
 * It exists so the importer states the shape it is reading instead of assuming one. The defaults
 * on the command describe the shape these tables almost always have; a table that spells a column
 * differently is then a matter of options rather than of code, and the reader of an invocation can
 * see which column fed which value.
 *
 * Reading goes through {@see self::of()} rather than straight off the row, so a column that is
 * absent from the result set is the same "not there" as a column that is null. A source table is
 * somebody else's schema: a missing column has to be an ordinary answer, not a PHP warning from
 * the middle of a chunk.
 *
 * @internal
 */
final readonly class LegacyCallColumns
{
    public function __construct(
        public string $id,
        public string $source,
        public string $payload,
        public string $headers,
        public string $error,
    ) {}

    /**
     * One column off one source row, or null when the row does not carry it.
     */
    public function of(stdClass $row, string $column): mixed
    {
        return $row->{$column} ?? null;
    }
}
