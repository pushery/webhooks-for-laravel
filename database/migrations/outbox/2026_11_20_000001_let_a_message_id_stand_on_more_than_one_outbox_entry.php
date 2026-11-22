<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\Webhooks\Database\DatabaseRequirement;
use Pushery\Webhooks\Support\TableLookup;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * Lets one message id stand on more than one entry of the outbox.
 *
 * An entry is one delivery, and its message id is the id of the event it delivers: the webhook-id a
 * receiver recognizes a repeat by. An event sent to two endpoints writes two entries under one
 * message id, and so does a delivery sent again while its first entry stands. The unique index on
 * message_id refused the second entry, and the transaction that wrote it rolled back together with
 * the change it reported, so no endpoint got the event. The message id keeps an index of its own,
 * and an entry outside a stream is relayed by its key.
 *
 * The create-table migration still builds the unique index, so a fresh install and an existing one
 * both reach the same table through this file. A step whose result already stands is left out: the
 * unique index is dropped only where it is there, and the plain one built only where it is missing.
 */
return new class extends Migration
{
    private const string UNIQUE = 'webhook_outbox_message_id_unique';

    private const string INDEX = 'webhook_outbox_message_id_index';

    public function getConnection(): ?string
    {
        return WebhookConnection::name();
    }

    public function up(): void
    {
        DatabaseRequirement::ensure($this->getConnection());

        $schema = Schema::connection($this->getConnection());
        $unique = $schema->hasIndex(TableLookup::qualified('webhook_outbox', $this->getConnection()), self::UNIQUE);
        $plain = $schema->hasIndex(TableLookup::qualified('webhook_outbox', $this->getConnection()), self::INDEX);

        $schema->table('webhook_outbox', static function (Blueprint $blueprint) use ($unique, $plain): void {
            if ($unique) {
                $blueprint->dropUnique(self::UNIQUE);
            }

            if (! $plain) {
                $blueprint->index('message_id', self::INDEX);
            }
        });
    }

    /**
     * Builds the unique index again. It is refused while two entries share a message id.
     */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $unique = $schema->hasIndex(TableLookup::qualified('webhook_outbox', $this->getConnection()), self::UNIQUE);
        $plain = $schema->hasIndex(TableLookup::qualified('webhook_outbox', $this->getConnection()), self::INDEX);

        $schema->table('webhook_outbox', static function (Blueprint $blueprint) use ($unique, $plain): void {
            if ($plain) {
                $blueprint->dropIndex(self::INDEX);
            }

            if (! $unique) {
                $blueprint->unique('message_id', self::UNIQUE);
            }
        });
    }
};
