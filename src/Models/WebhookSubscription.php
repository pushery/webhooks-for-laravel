<?php

declare(strict_types=1);

namespace Webhooks\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Override;
use Webhooks\Database\Concerns\HasZonedTimestamps;
use Webhooks\Database\Concerns\UsesWebhookConnection;
use Webhooks\Database\Factories\WebhookSubscriptionFactory;

/**
 * A registered webhook endpoint and the event types it listens for.
 *
 * @property int $id
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string|null $name
 * @property string $url
 * @property string $secret
 * @property string|null $previous_secret
 * @property Carbon|null $secret_rotated_at
 * @property array<int, string> $event_types
 * @property bool $is_active
 * @property Carbon|null $disabled_at
 * @property int $consecutive_failures
 * @property string|null $payload_version
 * @property array<string, mixed>|null $transform
 * @property int|null $health_score
 * @property string|null $health_status
 * @property Carbon|null $health_calculated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $owner
 * @property-read Collection<int, WebhookDelivery> $deliveries
 */
final class WebhookSubscription extends Model
{
    /** @use HasFactory<WebhookSubscriptionFactory> */
    use HasFactory;

    use HasZonedTimestamps;
    use UsesWebhookConnection;

    protected $table = 'webhook_subscriptions';

    /**
     * The endpoint's own descriptive columns — the only ones a host may mass-assign.
     *
     * is_active, disabled_at and consecutive_failures are DELIBERATELY absent: the three
     * move together and only through WebhookManager::enable() / disable(), because
     * re-activating an endpoint without clearing the failure streak that disabled it
     * re-trips the circuit breaker on the very next failure. Guarding the column makes
     * `$subscription->update(['is_active' => true])` — the obvious, wrong recipe —
     * impossible rather than subtly broken.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'url', 'event_types'];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'secret_rotated_at' => 'datetime',
            'is_active' => 'boolean',
            'disabled_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'transform' => 'array',
            'health_score' => 'integer',
            'health_calculated_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    /**
     * @param  Builder<WebhookSubscription>  $query
     * @return Builder<WebhookSubscription>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('disabled_at');
    }

    /**
     * @param  Builder<WebhookSubscription>  $query
     * @return Builder<WebhookSubscription>
     */
    public function scopeListeningFor(Builder $query, string $eventType): Builder
    {
        return $query->whereJsonContains('event_types', $eventType);
    }

    /**
     * Owner-less subscriptions are global; a tenant additionally receives the
     * events of subscriptions it owns.
     *
     * @param  Builder<WebhookSubscription>  $query
     * @return Builder<WebhookSubscription>
     */
    public function scopeForTenant(Builder $query, ?Model $tenant): Builder
    {
        return $query->where(function (Builder $inner) use ($tenant): void {
            $inner->whereNull('owner_id');

            if ($tenant instanceof Model) {
                $inner->orWhere(function (Builder $owned) use ($tenant): void {
                    $owned->where('owner_type', $tenant->getMorphClass())
                        ->where('owner_id', $tenant->getKey());
                });
            }
        });
    }

    protected static function newFactory(): WebhookSubscriptionFactory
    {
        return WebhookSubscriptionFactory::new();
    }
}
