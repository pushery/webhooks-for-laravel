<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Database\Dialect;

use Illuminate\Support\Facades\DB;

/**
 * The database dialect a piece of SQL is rendered for. The engine-specific SQL fragments the
 * layers share are produced by pure renderers under this namespace, keyed on this enum. Not
 * every engine difference lives here: PartitionManager (PostgreSQL partitioning) and
 * CollationAudit (MySQL collations) build their engine's SQL themselves, and other callers
 * branch on Dialect::for() or the driver name where a single decision differs.
 *
 * Only PostgreSQL and MySQL are dialects; DatabaseRequirement rejects everything else before
 * any renderer runs, so `for()` maps a guarded connection
 * and treats the PostgreSQL shape as the default.
 *
 * Part of the public API, because the migrations this package publishes call it. Once published, a
 * migration runs in the host's own namespace, and a host that analyzes its migrations reads every call
 * there into a class the package marks internal as an error it cannot fix without editing a published
 * file.
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
