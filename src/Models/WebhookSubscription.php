<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;
use Pushery\Webhooks\Database\Concerns\HasZonedTimestamps;
use Pushery\Webhooks\Database\Concerns\ScopesByTimestamp;
use Pushery\Webhooks\Database\Concerns\UsesWebhookConnection;
use Pushery\Webhooks\Database\Factories\WebhookSubscriptionFactory;
use Pushery\Webhooks\Livewire\SubscriptionManager;
use Pushery\Webhooks\Platform\Livewire\EndpointForm;
use Pushery\Webhooks\Support\EventTypeList;
use Pushery\Webhooks\Support\Settings;

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

    /**
     * Timestamp scopes, exactly as on the three log models. This table carries columns a
     * host legitimately filters by moment — `disabled_at` (which endpoints were auto-disabled
     * in the last 24h?), `secret_rotated_at`, `health_calculated_at` — and a plain
     * `->where('disabled_at', '>=', $moment)` binds a NAIVE literal that PostgreSQL resolves
     * against the database session zone. The window then shifts by that offset and the query
     * quietly returns the wrong rows.
     *
     * Without this the correct call was `->where('disabled_at', '>=', (new WebhookDelivery)->boundTimestamp($m))`
     * — borrowing the binding from an unrelated model, which reads like a mistake and invites
     * exactly the "cleanup" back to the silently-wrong form.
     */
    use ScopesByTimestamp;

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
     * The event types as the forms and the list screens declare them: a list of strings.
     *
     * The column is a JSON cast, so its shape is whatever was written into it. A row holding
     * a JSON object, a number or a nested array reaches a reader unchanged — and every reader
     * in this package treats it as a list of names. `implode()` on such a row emits an
     * "Array to string conversion" warning and prints the literal `Array`; a form binds
     * checkboxes against keys that are not indices and then refuses to save. Both screens go
     * dead over a value the operator never chose and has no control to remove.
     *
     * Reading through here rather than through the raw property is what keeps the four
     * readers agreeing. See {@see EventTypeList} for what is dropped and why.
     *
     * @return list<string>
     */
    public function eventTypeNames(): array
    {
        return EventTypeList::fromStorage($this->event_types);
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
     * Whether this endpoint was switched off by the circuit breaker rather than by a person.
     *
     * Both paths write the SAME two columns — `is_active = false` and a `disabled_at` stamp
     * ({@see WebhookManager::disable()} and the breaker in WebhookServerEventSubscriber) — so
     * the stamp cannot tell them apart. The failure STREAK can: the breaker only trips at or
     * above its threshold and leaves the streak standing, while a person switching an endpoint
     * off never touches it.
     *
     * ⚠️ The distinction changes what an operator should DO, which is why it is worth reading
     * out. Seeing only "Disabled", they re-enable — and `enable()` clears the streak by design,
     * so the endpoint gets a fresh failure budget, fails through it, and the breaker switches
     * it off again. The screen says the same thing on every pass, so the loop never looks like
     * one. When the breaker is what turned it off, the destination is what needs fixing.
     *
     * Answers false while the breaker is switched off entirely: a streak that no longer trips
     * anything cannot have tripped this.
     */
    public function wasAutoDisabled(): bool
    {
        $settings = new Settings;

        return ! $this->is_active
            && $settings->circuitBreakerEnabled()
            && $this->consecutive_failures >= $settings->circuitBreakerThreshold();
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
     * Subscriptions that listen for an event type. Exact matching by default; with
     * `webhooks.platform.wildcards` on, a subscription may also list a PREFIX WILDCARD
     * (`order.*`) and receives every event under that prefix. A concrete `order.line.added`
     * is then delivered to subscribers of `order.line.added`, `order.line.*` and `order.*` —
     * one prefix per dot boundary. Each arm is still a `whereJsonContains`, so the GIN /
     * multi-valued index serves the lookup as before.
     *
     * ⚠️ EVERY LOOKUP HERE DEPENDS ON `event_types` BEING A JSON *LIST*, and that is an
     * invariant the writers hold, not one the column enforces. `whereJsonContains` looks for a
     * member of an array; against an OBJECT it matches nothing — measured: a row stored as
     * `{"5":"invoice.paid"}` is invisible to `listeningFor('invoice.paid')` while looking
     * perfectly configured in both consoles, with no error, no empty state and no log line.
     *
     * The shape is reachable because both management components bind `$eventTypes` to a PUBLIC
     * Livewire property, so a payload decides its keys. All three writers therefore re-index:
     * {@see WebhookManager::subscribe()}, {@see EndpointForm} and
     * {@see SubscriptionManager}, each with its own arm. A fourth writer
     * needs one too — including anything a host reaches through `$fillable`.
     *
     * @param  Builder<WebhookSubscription>  $query
     * @return Builder<WebhookSubscription>
     */
    public function scopeListeningFor(Builder $query, string $eventType): Builder
    {
        if (! Config::boolean('webhooks.platform.wildcards', false)) {
            return $query->whereJsonContains('event_types', $eventType);
        }

        return $query->where(function (Builder $inner) use ($eventType): void {
            $inner->whereJsonContains('event_types', $eventType);

            foreach (new Settings()->wildcardsFor($eventType) as $wildcard) {
                $inner->orWhereJsonContains('event_types', $wildcard);
            }
        });
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
