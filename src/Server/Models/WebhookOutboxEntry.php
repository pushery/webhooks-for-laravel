<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Override;
use Pushery\Webhooks\Database\Concerns\HasZonedTimestamps;
use Pushery\Webhooks\Database\Concerns\Replaceable;
use Pushery\Webhooks\Database\Concerns\ScopesByTimestamp;
use Pushery\Webhooks\Database\Concerns\UsesWebhookConnection;
use Pushery\Webhooks\Database\Factories\WebhookOutboxEntryFactory;
use Pushery\Webhooks\Enums\OutboxEntryState;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;

/**
 * An entry of the transactional outbox: one webhook, written in the transaction of the change it
 * reports and sent once that transaction has committed.
 *
 * The row carries the whole delivery, sealed with the application key the way a queued job
 * carries it, so every attempt sends the same bytes under the same message id. The columns beside
 * it are what a host queries: the redacted URL, the event type, the meta the delivery was recorded
 * with, where the entry stands and how its attempts went.
 *
 * An entry with an ordering key belongs to a stream, the same one an ordered delivery to that URL
 * under that key would. `sequence` counts the entries of a stream in the order their transactions
 * committed, and an entry goes out only once every earlier entry of its stream was delivered or
 * skipped.
 *
 * Delivered and skipped entries are removed once older than
 * webhooks.server.outbox.prune_after_days. A refused entry and one the outbox gave up on stay,
 * because they hold their stream until they are sent again or skipped.
 *
 * @property int $id
 * @property string $message_id
 * @property string|null $stream
 * @property int|null $sequence
 * @property string $url
 * @property string|null $event_type
 * @property array<string, scalar|null>|null $meta
 * @property string $delivery
 * @property OutboxEntryState $state
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property string|null $claimed_by
 * @property int|null $last_status
 * @property string|null $last_error
 * @property Carbon|null $alarmed_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookOutboxEntry extends Model
{
    /** @use HasFactory<WebhookOutboxEntryFactory> */
    use HasFactory;

    use HasZonedTimestamps;
    use MassPrunable;
    use Replaceable;
    use ScopesByTimestamp;
    use UsesWebhookConnection;

    protected $table = 'webhook_outbox';

    /**
     * Nothing is mass-assignable: the outbox writes every column itself, through forceFill(), so
     * a stray create() or fill() from host code can neither forge an outcome nor reopen an entry.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * The sealed delivery stays out of the array and JSON forms. It is ciphertext, and nothing a
     * reader of a logged or rendered entry can use.
     *
     * @var list<string>
     */
    protected $hidden = ['delivery'];

    /**
     * The delivery this entry sends, unsealed. Null when it can no longer be read: the
     * application key it was sealed with is gone, or the row was written by hand.
     */
    public function deliveryData(): ?WebhookDeliveryData
    {
        try {
            $data = Crypt::decrypt($this->delivery);
        } catch (DecryptException) {
            return null;
        }

        if (! $data instanceof WebhookDeliveryData) {
            return null;
        }

        return $data;
    }

    /**
     * The entries eligible for pruning: delivered and skipped ones whose end lies further back than
     * the configured window. A refused entry and one the outbox gave up on are never pruned here,
     * because removing them would let their stream move on without anybody deciding it should.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::model()::query()
            ->whereIn('state', [OutboxEntryState::Delivered->value, OutboxEntryState::Skipped->value])
            ->where(
                'ended_at',
                '<=',
                $this->boundTimestamp(Date::now()->subDays(Config::integer('webhooks.server.outbox.prune_after_days', 14))),
            );
    }

    protected static function newFactory(): WebhookOutboxEntryFactory
    {
        return WebhookOutboxEntryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'state' => OutboxEntryState::class,
            'meta' => 'array',
            'sequence' => 'integer',
            'attempts' => 'integer',
            'last_status' => 'integer',
            'next_attempt_at' => 'datetime',
            'alarmed_at' => 'datetime',
            'ended_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
