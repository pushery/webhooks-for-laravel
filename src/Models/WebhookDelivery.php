<?php

declare(strict_types=1);

namespace Webhooks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;
use Webhooks\Database\Factories\WebhookDeliveryFactory;
use Webhooks\Enums\DeliveryStatus;

/**
 * A single delivery-log entry: one event sent to one subscription.
 *
 * @property string $id
 * @property int $subscription_id
 * @property string $event_type
 * @property string $event_id
 * @property array<string, mixed> $payload
 * @property DeliveryStatus $status
 * @property int $attempt
 * @property int|null $response_code
 * @property int|null $response_ms
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon|null $delivered_at
 * @property-read WebhookSubscription $subscription
 */
final class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The log is append-then-update-in-place; it tracks created_at and
     * delivered_at, but has no updated_at column.
     */
    public const UPDATED_AT = null;

    protected $table = 'webhook_deliveries';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => DeliveryStatus::class,
            'attempt' => 'integer',
            'response_code' => 'integer',
            'response_ms' => 'integer',
            'created_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
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
}
