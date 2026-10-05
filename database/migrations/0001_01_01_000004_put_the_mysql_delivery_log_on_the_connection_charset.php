<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * Moves the unpinned text columns of an EXISTING MySQL delivery log onto the connection's character
 * set.
 *
 * The create migration names the connection's character set and collation as the table's default
 * now, as the schema builder does for every other table. That reaches a fresh install and nothing
 * else: a delivery log created on a database whose default is latin1 or utf8mb3 keeps
 * `payload_type`, `payload_disk`, `payload_path` and `error` on that default, and refuses a payload
 * type or an error text outside it with 1366, the delivery with it.
 *
 * MySQL only, only when the connection names a character set, and only while one of the four
 * columns is on another one. The columns the create migration pins keep their collation. Changing a
 * column's character set rebuilds the table, so on such a database this migration takes as long as
 * copying the delivery log once; anywhere else it changes nothing.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return WebhookConnection::name();
    }

    public function up(): void
    {
        // Default-deny, like every migration in this package: DatabaseRequirement rather than
        // PostgresRequirement, because this file is the MySQL half and has to be allowed to run there.
        DatabaseRequirement::ensure($this->getConnection());

        if (Dialect::for($this->getConnection()) !== Dialect::MySql) {
            return;
        }

        $connection = DB::connection($this->getConnection());
        $charset = $connection->getConfig('charset');
        $collation = $connection->getConfig('collation');
        $collation = is_string($collation) && $collation !== '' ? $collation : null;

        if (! is_string($charset) || $charset === '' || ! $connection->getSchemaBuilder()->hasTable('webhook_deliveries')) {
            return;
        }

        $current = array_map(
            static function (mixed $row): string {
                $name = is_object($row) ? (get_object_vars($row)['charset'] ?? null) : null;

                return is_string($name) ? $name : '';
            },
            $connection->select(
                'select character_set_name as charset from information_schema.columns '
                ."where table_schema = database() and table_name = 'webhook_deliveries' "
                .'and column_name in (?, ?, ?, ?)',
                ['payload_type', 'payload_disk', 'payload_path', 'error'],
            ),
        );

        // MySQL reports the character set utf8 under its full name.
        if (array_diff($current, [$charset === 'utf8' ? 'utf8mb3' : $charset]) === []) {
            return;
        }

        $connection->statement('alter table webhook_deliveries default character set '.$charset.($collation === null ? '' : " collate '{$collation}'"));

        Schema::connection($this->getConnection())->table('webhook_deliveries', static function (Blueprint $table) use ($charset, $collation): void {
            foreach ([
                $table->mediumText('payload_type')->storedAs('json_unquote(json_extract(payload, \'$.type\'))'),
                $table->string('payload_disk')->nullable(),
                $table->string('payload_path')->nullable(),
                $table->mediumText('error')->nullable(),
            ] as $column) {
                $column->charset($charset);

                if ($collation !== null) {
                    $column->collation($collation);
                }

                $column->change();
            }
        });
    }

    /**
     * Deliberately empty. Down would put the columns back on a character set that refuses the texts
     * this migration makes room for, which is the state it exists to end.
     */
    public function down(): void {}
};
