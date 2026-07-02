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
use Webhooks\Database\Factories\WebhookSubscriptionFactory;

/**
 * A registered webhook endpoint and the event types it listens for.
 *
 * @property int $id
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property string|null $name
 * @property string $url
 * @property string $secret
 * @property string|null $previous_secret
 * @property Carbon|null $secret_rotated_at
 * @property array<int, string> $event_types
 * @property bool $is_active
 * @property Carbon|null $disabled_at
 * @property int $consecutive_failures
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $owner
 * @property-read Collection<int, WebhookDelivery> $deliveries
 */
final class WebhookSubscription extends Model
{
    /** @use HasFactory<WebhookSubscriptionFactory> */
    use HasFactory;

    protected $table = 'webhook_subscriptions';

    /** @var list<string> */
    protected $fillable = ['name', 'url', 'event_types', 'is_active'];

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
     * The secrets to sign a delivery with — the current secret plus, during a
     * rotation window, the previous one, so consumers can update at their leisure.
     */
    public function signingSecrets(): string
    {
        $secrets = [$this->secret];

        if ($this->previous_secret !== null) {
            $secrets[] = $this->previous_secret;
        }

        return implode("\n", $secrets);
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
