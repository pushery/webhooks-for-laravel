<?php

declare(strict_types=1);

namespace Webhooks\Server\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Override;
use Webhooks\Database\Concerns\HasZonedTimestamps;
use Webhooks\Database\Concerns\UsesWebhookConnection;
use Webhooks\Database\Factories\WebhookServerDeliveryFactory;
use Webhooks\Enums\DeliveryStatus;
use Webhooks\Support\Timestamp;

/**
 * A single row in the standalone Server delivery log — one delivered message,
 * keyed by its Standard Webhooks message id. The persistence listener upserts each
 * attempt onto this row; rows age out once older than
 * webhooks.server.persistence.prune_after_days via the scheduled model:prune.
 *
 * This is the send-without-Platform record: when the Platform layer runs it owns
 * the richer webhook_deliveries log instead, so this table is created and written
 * only while standalone persistence is enabled.
 *
 * @property int $id
 * @property string $uuid
 * @property string $message_id
 * @property string $url
 * @property string|null $event_type
 * @property DeliveryStatus $status
 * @property int|null $http_status
 * @property int $attempt
 * @property int|null $duration_ms
 * @property string|null $error
 * @property list<string>|null $tags
 * @property Carbon|null $created_at
 * @property Carbon|null $delivered_at
 */
class WebhookServerDelivery extends Model
{
    /** @use HasFactory<WebhookServerDeliveryFactory> */
    use HasFactory;

    use HasZonedTimestamps;
    use MassPrunable;
    use UsesWebhookConnection;

    /**
     * The log is append-then-update-in-place, tracking created_at and delivered_at
     * only; there is no updated_at column.
     */
    public const UPDATED_AT = null;

    protected $table = 'webhook_server_deliveries';

    protected $guarded = [];

    /**
     * The rows eligible for pruning: everything older than the configured window.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where(
            'created_at',
            '<=',
            Timestamp::sql(Date::now()->subDays(Config::integer('webhooks.server.persistence.prune_after_days', 30))),
        );
    }

    #[Override]
    protected static function booted(): void
    {
        // Guarantee the unique public id is always populated, whoever creates the
        // row — the listener's upsert or a factory.
        static::creating(static function (self $delivery): void {
            $uuid = $delivery->getAttribute('uuid');

            if (! is_string($uuid) || $uuid === '') {
                $delivery->uuid = (string) Str::uuid7();
            }
        });
    }

    protected static function newFactory(): WebhookServerDeliveryFactory
    {
        return WebhookServerDeliveryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'tags' => 'array',
            'http_status' => 'integer',
            'attempt' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
