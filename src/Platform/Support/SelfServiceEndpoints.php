<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Support;

use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Pushery\Webhooks\Core\Http\Exceptions\BlockedDestination;
use Pushery\Webhooks\Core\Http\Exceptions\HostUnresolvable;
use Pushery\Webhooks\Core\Ssrf\SsrfGuard;
use Pushery\Webhooks\Enums\DeliveryStatus;
use Pushery\Webhooks\Facades\Webhooks;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Server\Exceptions\DeliveryRefused;
use Pushery\Webhooks\Support\EventTypeList;
use Pushery\Webhooks\Support\ReplayAllowance;
use Pushery\Webhooks\Support\Settings;
use Pushery\Webhooks\Support\TenantIdentity;

/**
 * Registers and changes a tenant's endpoint, reads the tenant's delivery log and replays a delivery, under the rules of
 * the self-service portal, for a host that offers them through an API of its own. The portal's form saves through this
 * class, so both answer alike: the same validation and messages, the same ability check per event type, the same
 * registration brake, the same cap under the tenant's registration lock, and the same check of the URL against
 * internal addresses. The log and the replay keep the rules of the portal's delivery panel.
 *
 * Every refusal is a ValidationException on the field it concerns, `name`, `url`, `eventTypes` or `delivery`, with the
 * portal's translated message, so a JSON request is answered 422 with the message on its field.
 *
 * Who may register an endpoint for a tenant, change an endpoint, read a log or replay a delivery is the caller's
 * decision, taken before the call: the actor passed in is asked only for the abilities an event type's catalog entry
 * names.
 */
final class SelfServiceEndpoints
{
    /**
     * How many days back the delivery log reaches when the host sets no window, the portal's own default.
     */
    private const int WINDOW_DAYS = 30;

    /**
     * Register an endpoint for the tenant, active unless `$active` says otherwise, and return it. Its signing secret is
     * generated and stored encrypted, as for every endpoint the manager registers.
     *
     * @param  array<array-key, string>  $eventTypes
     *
     * @throws ValidationException when the registration is refused, on the field the refusal concerns
     */
    public function register(
        TenantIdentity $owner,
        string $url,
        array $eventTypes,
        ?string $name = null,
        bool $active = true,
        ?Authenticatable $actor = null,
    ): WebhookSubscription {
        $this->validate($url, $eventTypes, $name, []);
        $this->refuseTypesTheActorLacks($eventTypes, [], $actor);

        // Before the lock, not inside it: a tenant that is already over its allowance has no business making everyone
        // behind it wait for a lock it will be refused under.
        if (EndpointRegistration::rateExceeded($owner)) {
            throw $this->refusal('url', __('webhooks::self-service.registration_throttled'));
        }

        try {
            // The cap is read inside the lock, and that is the whole point: read outside it, the count is a fact about a
            // moment that has already passed by the time the insert runs, and two concurrent registrations both pass it.
            $endpoint = EndpointRegistration::underLock($owner, function () use ($owner, $url, $eventTypes, $name): WebhookSubscription {
                $refusal = EndpointRegistration::refusal($owner, __('webhooks::self-service.limit_reached'));

                if ($refusal !== null) {
                    throw $this->refusal('url', $refusal);
                }

                try {
                    // Through the manager, so the URL is checked against internal addresses and the signing secret is
                    // generated and encrypted by the existing scheme. The owner is stored as the tenant identity the
                    // portal's scope filters by, so create and read can never diverge onto different owner columns.
                    return Webhooks::subscribe($owner, $url, array_values($eventTypes), $this->nameOrNull($name));
                } catch (BlockedDestination|HostUnresolvable) {
                    throw $this->refusal('url', $this->blockedUrlMessage());
                }
            });
        } catch (LockTimeoutException) {
            // Another registration of this tenant held the lock past the wait. Not the cap: saying "you are at your
            // limit" here would be a lie the tenant cannot act on, and the honest instruction is to try again.
            throw $this->refusal('url', __('webhooks::self-service.limit_busy'));
        }

        if (! $active) {
            Webhooks::disable($endpoint);
        }

        return $endpoint;
    }

    /**
     * Change an endpoint's URL, event types, name and activation, and return it. A type the endpoint already carries
     * stays allowed through the change, even where the catalog no longer declares it or the actor lacks its ability,
     * and removing one asks nothing.
     *
     * @param  array<array-key, string>  $eventTypes
     *
     * @throws ValidationException when the change is refused, on the field the refusal concerns
     */
    public function change(
        WebhookSubscription $endpoint,
        string $url,
        array $eventTypes,
        ?string $name = null,
        bool $active = true,
        ?Authenticatable $actor = null,
    ): WebhookSubscription {
        // Read once: what the endpoint already holds widens the catalog and the caps, and is the part of the selection no
        // ability is asked for.
        $stored = $endpoint->eventTypeNames();

        $this->validate($url, $eventTypes, $name, $stored);
        $this->refuseTypesTheActorLacks($eventTypes, $stored, $actor);

        try {
            // Checked again before the endpoint is repointed, whether the URL changed or not.
            Container::getInstance()->make(SsrfGuard::class)->resolveAndPin($url);
        } catch (BlockedDestination|HostUnresolvable) {
            throw $this->refusal('url', $this->blockedUrlMessage());
        }

        $endpoint->name = $this->nameOrNull($name);
        $endpoint->url = $url;
        $endpoint->event_types = array_values($eventTypes);
        $endpoint->save();

        // The activation flag goes through the manager, never through an assignment: switching an endpoint back on has to
        // clear the circuit-breaker streak too, or the next final failure disables it again immediately. An unchanged
        // flag is left alone, so saving a disabled endpoint again keeps the disabled_at stamp it already carries.
        if ($active !== $endpoint->is_active) {
            $active
                ? Webhooks::enable($endpoint)
                : Webhooks::disable($endpoint);
        }

        return $endpoint;
    }

    /**
     * The tenant's deliveries, newest first, under the rules of the portal's delivery log. Only the deliveries whose own
     * owner columns name the tenant, so no relation could widen the scope; within the window
     * `webhooks.platform.deliveries.window_days` sets, which keeps the read on the partitions it needs; never the
     * payload; and the stored error text only where `webhooks.platform.deliveries.show_errors` allows it, because it can
     * quote back whatever a receiver wrote.
     *
     * `$endpoint` narrows the log to one endpoint of the tenant, and one the tenant does not own is not found rather than
     * an empty list, which would read as an answer about it. `$status` narrows it to one outcome, and `$since` moves the
     * lower bound later, never earlier than the window allows.
     *
     * @return Builder<WebhookDelivery>
     *
     * @throws ModelNotFoundException<WebhookSubscription> when `$endpoint` names no endpoint of the tenant
     */
    public function deliveries(
        TenantIdentity $owner,
        ?int $endpoint = null,
        ?DeliveryStatus $status = null,
        ?CarbonInterface $since = null,
    ): Builder {
        // The columns the panel renders, and the endpoint each delivery went to, which a log across endpoints has to name.
        $columns = ['id', 'subscription_id', 'event_type', 'status', 'response_code', 'duration_ms', 'created_at'];

        if (Config::boolean('webhooks.platform.deliveries.show_errors', false)) {
            $columns[] = 'error';
        }

        $query = WebhookDelivery::model()::query()
            ->select($columns)
            ->where('owner_type', $owner->type)
            ->where('owner_id', $owner->id);

        if ($endpoint !== null) {
            $query->where('subscription_id', SubscriptionScope::forOwner($owner->type, $owner->id)->findOrFail($endpoint)->id);
        }

        $days = Config::integer('webhooks.platform.deliveries.window_days', self::WINDOW_DAYS);

        // A window of zero or less is the host's deliberate choice to read the whole log, as in the panel.
        if ($days > 0) {
            $query->createdAfter(Date::now()->subDays($days)->startOfDay());
        }

        // Beside the window rather than in its place, so it can only narrow what the window allows.
        if ($since instanceof CarbonInterface) {
            $query->createdAfter($since);
        }

        if ($status instanceof DeliveryStatus) {
            $query->where('status', $status->value);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Send one of the tenant's deliveries to its endpoint again, under the rules of the portal's replay, and return the
     * delivery the replay made. The original event id is kept, so a receiver that deduplicates on it sees the same event.
     *
     * The delivery and its endpoint are looked up through the tenant's owner pair, so another tenant's delivery is not
     * found rather than refused, and a probe cannot tell it from one that does not exist. A replay to a disabled endpoint
     * is refused. Each replay that gets past both spends one of the tenant's replays per minute
     * (`webhooks.platform.self_service.replays_per_minute`), the allowance the portal and the tenant's dashboard spend
     * as well, so an API of the host is no way around it.
     *
     * @throws ModelNotFoundException<WebhookDelivery|WebhookSubscription> when the delivery or its endpoint is not the tenant's
     * @throws ValidationException on `delivery`, when the endpoint is switched off or the tenant has spent its replays
     */
    public function redeliver(TenantIdentity $owner, WebhookDelivery|string $delivery): WebhookDelivery
    {
        // Loaded again through the owner pair even when a model is handed in: the pair is what decides whose it is.
        $stored = WebhookDelivery::model()::query()
            ->where('owner_type', $owner->type)
            ->where('owner_id', $owner->id)
            ->whereSubmittedKey($delivery instanceof WebhookDelivery ? $delivery->id : $delivery)
            ->firstOrFail();

        $endpoint = SubscriptionScope::forOwner($owner->type, $owner->id)->findOrFail($stored->subscription_id);

        if (! $endpoint->is_active) {
            throw $this->refusal('delivery', __('webhooks::self-service.deliveries.endpoint_disabled'));
        }

        if (! ReplayAllowance::spend($owner)) {
            throw $this->refusal('delivery', __('webhooks::self-service.deliveries.replay_throttled'));
        }

        try {
            return Webhooks::redeliver($stored);
        } catch (DeliveryRefused) {
            // Switched off between the check above and the send, by the circuit breaker on a concurrent failure or by the
            // tenant in another request. The engine refuses the replay, and the answer is the portal's sentence.
            throw $this->refusal('delivery', __('webhooks::self-service.deliveries.endpoint_disabled'));
        }
    }

    /**
     * Validate a registration or a change. The messages and attribute names come from the package's own translations
     * rather than the framework's default lines, so a refusal speaks the reader's language on a host that never
     * translated Laravel's validation file.
     *
     * @param  array<array-key, string>  $eventTypes
     * @param  list<string>  $stored  the types the endpoint already carries, none for a registration
     *
     * @throws ValidationException
     */
    private function validate(string $url, array $eventTypes, ?string $name, array $stored): void
    {
        $accepted = new Settings()->acceptedEventTypes();

        if ($accepted !== null && $stored !== []) {
            // array_unique matters here: the list also sets the cap EventTypeList::maxCount() puts on a save, and without
            // it a type both declared and already held would count twice, raising the cap by one for each. array_values
            // cannot change an outcome, since neither that count nor Rule::in reads a key; it stays because the value is
            // passed on as a list.
            $accepted = array_values(array_unique([...$accepted, ...$stored]));
        }

        $maxBytes = EventTypeList::maxBytes($stored);

        Validator::make(
            ['name' => $name, 'url' => $url, 'eventTypes' => $eventTypes],
            // Some of these restate what the parameter types already enforce. They stay: this list is the written
            // contract of a save, and what reaches it from a browser or a request body is not always what its type
            // declares. 'required', 'url', 'max:2048' and 'max:255' are reachable, each by an input of its own.
            [
                'name' => ['nullable', 'string', 'max:255'],
                // Bound the URL length so it stores identically on every supported engine: the column is varchar(2048) on
                // MySQL (a hard 1406 error past it) but unbounded text on PostgreSQL, so without this cap the same long
                // URL would fail on one engine and store on the other.
                //
                // `url:http,https`, not a bare `url`. Laravel's bare rule answers through Str::isUrl(), which checks a
                // built-in list of over 200 schemes: ftp, file, data and chrome all pass it. Without the narrowing a
                // foreign scheme was refused only by the check against internal addresses, after the registration brake
                // had spent a token, the lock had been taken and the cap queried, and it answered with the generic
                // "cannot be used as an endpoint" instead of the field message that exists for exactly this. That check
                // stays the authority; the rule only takes from it the cases that never needed to reach it.
                'url' => ['required', 'url:http,https', 'max:2048'],
                // Constrained to the catalog, and only when the host keeps one. The catalog ships empty, so an `in` rule
                // applied unconditionally would refuse every registration the day an application upgrades; null means
                // "no catalog, no constraint".
                //
                // What a populated catalog constrains is registration, not dispatch: the fan-out never consults it, so an
                // application can still emit a type it does not document. What it buys is that a save stops accepting a
                // type it never offered: a typo like `user.registred` produced an endpoint that looked configured, stayed
                // silent, and was indistinguishable from a correct registration until someone noticed nothing arriving.
                //
                // Bounded either way. Without a catalog nothing else limits what a hand-written request stores per
                // endpoint, and MySQL refuses a type longer than its index. MySQL's index also bounds the bytes of all
                // the types together, with or without a catalog, so on MySQL a longer selection is a field error here
                // rather than a server error from the insert.
                'eventTypes' => [
                    'required',
                    'array',
                    'min:1',
                    'max:'.EventTypeList::maxCount($accepted, $stored),
                    EventTypeList::fitsTheMySqlIndex($maxBytes, (string) __(
                        'webhooks::self-service.validation.event_types.bytes',
                        ['max' => $maxBytes],
                    )),
                ],
                'eventTypes.*' => $accepted === null
                    ? ['string', 'max:'.EventTypeList::MAX_LENGTH]
                    : ['string', Rule::in($accepted)],
            ],
            // Every rule above names its message, so a refusal never falls back to the framework's untranslated
            // ":attribute is invalid". A non-string element in particular would otherwise render "The eventTypes.0
            // field must be a string.", in English whatever the locale and with a raw field path in it.
            [
                'name.max' => __('webhooks::self-service.validation.name.max'),
                'url.required' => __('webhooks::self-service.validation.url.required'),
                'url.url' => __('webhooks::self-service.validation.url.url'),
                'url.max' => __('webhooks::self-service.validation.url.max'),
                'eventTypes.required' => __('webhooks::self-service.validation.event_types.required'),
                'eventTypes.min' => __('webhooks::self-service.validation.event_types.min'),
                'eventTypes.max' => __('webhooks::self-service.validation.event_types.max'),
                'eventTypes.*.string' => __('webhooks::self-service.validation.event_types.string'),
                'eventTypes.*.in' => __('webhooks::self-service.validation.event_types.in'),
                'eventTypes.*.max' => __('webhooks::self-service.validation.event_types.length'),
            ],
            // Inert while every message above is written without `:attribute`, and kept for the moment a rule loses its
            // own message: the framework's default then asks for exactly these. They cannot rescue an indexed key —
            // `eventTypes.0` does not resolve onto the `eventTypes` label — which is why that rule has its own message.
            [
                'name' => __('webhooks::self-service.form.name_label'),
                'url' => __('webhooks::self-service.form.url_label'),
                'eventTypes' => __('webhooks::self-service.form.event_types_label'),
            ],
        )->validate();
    }

    /**
     * Refuse the types a save adds that take an ability the actor lacks. Asked before the registration brake and the
     * lock, so a refused type costs the tenant nothing. Only the types the save adds: one the endpoint already carries
     * stays through a change by somebody without the ability, and removing it asks nothing.
     *
     * @param  array<array-key, string>  $eventTypes
     * @param  list<string>  $stored
     *
     * @throws ValidationException
     */
    private function refuseTypesTheActorLacks(array $eventTypes, array $stored, ?Authenticatable $actor): void
    {
        $refused = new EventTypeAbilities()->refused(array_values(array_diff($eventTypes, $stored)), $actor);

        if ($refused !== []) {
            throw $this->refusal('eventTypes', __('webhooks::self-service.validation.event_types.ability', ['types' => implode(', ', $refused)]));
        }
    }

    /**
     * The URL message a tenant reads when the check against internal addresses refuses the destination. That check's
     * own message names the resolved host and address, which would turn a save into a probe oracle; the tenant is told
     * the one thing it can act on — the endpoint must be a publicly reachable https URL — in its own language. The
     * precise reason still reaches the operator through the exception itself.
     *
     * A host that resolves to nothing gets the same sentence. Told apart, the two answers would say which internal
     * names exist: one that resolves to a private address is refused as blocked, one that does not resolve would be
     * refused differently.
     */
    private function blockedUrlMessage(): string
    {
        return __('webhooks::self-service.validation.url.blocked');
    }

    /** An empty name is no name. */
    private function nameOrNull(?string $name): ?string
    {
        if ($name !== '') {
            return $name;
        }

        return null;
    }

    /** A refusal on one field, as a host answers it: 422, with the message on the field. */
    private function refusal(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
