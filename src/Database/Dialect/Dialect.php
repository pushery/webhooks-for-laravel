<?php

declare(strict_types=1);

namespace Webhooks\Database\Dialect;

use Illuminate\Support\Facades\DB;

/**
 * The database dialect a piece of SQL is rendered for. Every engine-specific SQL fragment
 * in the package is produced by a pure renderer under this namespace, keyed on this enum —
 * so a dialect difference lives in exactly one place and the layers stay driver-agnostic.
 *
 * Only PostgreSQL and MySQL are dialects; DatabaseRequirement rejects everything else before
 * any renderer runs, so `for()` maps a guarded connection
 * and treats the PostgreSQL shape as the default.
 *
 * @internal
 */
enum Dialect
{
    case Pgsql;
    case MySql;

    /**
     * The dialect of the given connection (the application default when null).
     */
    public static function for(?string $connection = null): self
    {
        return DB::connection($connection)->getDriverName() === 'mysql'
            ? self::MySql
            : self::Pgsql;
    }
}
