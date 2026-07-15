<?php

declare(strict_types=1);

namespace Webhooks\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;
use Webhooks\Core\Payload\PayloadStore;
use Webhooks\Database\Concerns\HasZonedTimestamps;
use Webhooks\Database\Concerns\ScopesByTimestamp;
use Webhooks\Database\Concerns\UsesWebhookConnection;
use Webhooks\Database\Factories\WebhookDeliveryFactory;
use Webhooks\Database\OwnerKeyType;
use Webhooks\Enums\DeliveryStatus;

/**
 * A single delivery-log entry: one event sent to one subscription.
 *
 * @property string $id
 * @property int $subscription_id
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string $event_type
 * @property string $event_id
 * @property array<string, mixed> $payload
 * @property string|null $payload_disk
 * @property string|null $payload_path
 * @property string|null $body_sha256
 * @property DeliveryStatus $status
 * @property int $attempt
 * @property int|null $response_code
 * @property int|null $duration_ms
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon|null $delivered_at
 * @property-read string|null $payload_type
 * @property-read WebhookSubscription $subscription
 */
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    use HasUuids;
    use HasZonedTimestamps;
    use ScopesByTimestamp;
    use UsesWebhookConnection;

    /**
     * The log is append-then-update-in-place; it tracks created_at and
     * delivered_at, but has no updated_at column.
     */
    public const UPDATED_AT = null;

    protected $table = 'webhook_deliveries';

    /**
     * Only the event-content columns are mass-assignable. The tenant/identity columns
     * (subscription_id, owner_type, owner_id) and the delivery-outcome columns
     * (status, attempt, response_code, duration_ms, delivered_at, error) are written
     * exclusively by the engine via forceFill(), so a stray create()/fill() from host
     * code can never re-own a log row or forge its outcome.
     *
     * @var list<string>
     */
    protected $fillable = [
        'event_type',
        'event_id',
        'payload',
        'payload_disk',
        'payload_path',
        'body_sha256',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => DeliveryStatus::class,
            // owner_id follows the configured owner_key_type: an integer cast for the bigint
            // default, a plain string for a uuid/ulid owner (which must not be numeric-coerced).
            'owner_id' => OwnerKeyType::fromConfig() === OwnerKeyType::Bigint ? 'integer' : 'string',
            'attempt' => 'integer',
            'response_code' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * The full logged payload. A delivery whose over-sized payload was offloaded to
     * a Storage disk keeps only a pointer in the row; this reads the original array
     * back on demand so a redelivery re-sends the exact event. An inline delivery
     * returns its stored payload untouched.
     *
     * @return array<array-key, mixed>
     */
    public function rehydratedPayload(): array
    {
        if ($this->payload_disk === null || $this->payload_path === null) {
            return $this->payload;
        }

        $decoded = json_decode(
            app(PayloadStore::class)->rehydrate($this->payload_disk, $this->payload_path),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return BelongsTo<WebhookSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    protected static function newFactory(): WebhookDeliveryFactory
    {
        return WebhookDeliveryFactory::new();
    }

    /**
     * Deliveries that have been waiting to send since at least $moment: still Pending and
     * created at or before it. This is the health-check query — "are deliveries piling up
     * undelivered because no worker is running?" — expressed so a host cannot bind a naive,
     * session-zone-resolved timestamp by accident (see {@see ScopesByTimestamp}).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendingSince(Builder $query, DateTimeInterface $moment): Builder
    {
        return $query
            ->where('status', DeliveryStatus::Pending->value)
            ->where('created_at', '<=', $this->boundTimestamp($moment));
    }

    /**
     * Scope a save to the row's PARTITION as well as its id.
     *
     * The table is PARTITION BY RANGE (created_at) and its primary key is
     * (id, created_at). Eloquent would update WHERE id = ?, which the planner cannot
     * prune with, so every write to the delivery log would touch the primary-key index
     * of every partition that exists — and each delivery is written three times on the
     * engine's hot path. Adding the partition key sends each write straight to the one
     * partition that holds the row.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Override]
    protected function setKeysForSaveQuery($query): Builder
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());

        $createdAt = $this->fromDateTime($this->getRawOriginal('created_at'));

        if (is_string($createdAt)) {
            $query->where('created_at', '=', $createdAt);
        }

        return $query;
    }
}
