<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * Drops `exception` from an EXISTING webhook_calls table.
 *
 * The column was declared when this table was first created and NOTHING in this package ever
 * wrote it — not the live receive path, not the backlog import, not a listener. It was carried in
 * from the shape of the table this log replaced, survived the rewrite that removed the dependency
 * it came from, and then sat there: declared on the model as `@property string|null $exception`,
 * present in every installation, and null on every row.
 *
 * That is worse than an unused column, because it reads as a feature. The documentation promised
 * the backlog import filled it, and the import never did — a reader migrating years of failure
 * history for exactly those traces found `status = failed` beside `exception = null`, typically
 * after the source table was the only other copy. Removing the column removes the thing the wrong
 * promise was made about; the status it decided is unchanged and still imported.
 *
 * The create-table migration no longer declares it, and that covers a fresh install and nothing
 * else. A host that already migrated will never re-run that file, so without this one the column
 * would live on in every existing installation forever — the same asymmetry the index migration
 * beside this one exists for. `hasColumn()` makes it a no-op on a fresh install rather than an
 * error.
 *
 * It is a drop, so it is destructive if you used the column yourself. It is ours to define — a
 * package-owned table — and nothing in the package wrote it, but a host that adopted it for its
 * own bookkeeping has data in it. Copy it out before migrating; `down()` restores the column and
 * cannot restore its contents, and says so rather than implying a rollback undoes this.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return WebhookConnection::name();
    }

    public function up(): void
    {
        // The same tier check the create-table migration makes, for the same reason: reject an
        // unsupported engine here rather than through a raw error further down.
        DatabaseRequirement::ensure($this->getConnection());

        if (! Schema::connection($this->getConnection())->hasColumn('webhook_calls', 'exception')) {
            return;
        }

        Schema::connection($this->getConnection())->table('webhook_calls', function (Blueprint $table): void {
            $table->dropColumn('exception');
        });
    }

    /**
     * Restores the column's SHAPE, per engine, and not its contents — the drop above took those.
     * A rollback that silently produced an all-null column where data used to be would be the
     * quieter failure, so the class docblock says to copy the values out first.
     */
    public function down(): void
    {
        if (Schema::connection($this->getConnection())->hasColumn('webhook_calls', 'exception')) {
            return;
        }

        $mysql = Dialect::for($this->getConnection()) === Dialect::MySql;

        Schema::connection($this->getConnection())->table('webhook_calls', function (Blueprint $table) use ($mysql): void {
            $mysql
                ? $table->mediumText('exception')->nullable()
                : $table->text('exception')->nullable();
        });
    }
};
