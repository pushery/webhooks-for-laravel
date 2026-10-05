<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Pushery\Webhooks\Support\Settings;

/**
 * Which event types a person may subscribe an endpoint to, by the ability a catalog entry names.
 *
 * A catalog entry may name an ability, and subscribing to its type then takes that ability on top
 * of whatever the surface itself asks. A prefix wildcard takes the abilities of every declared type
 * it covers, because the fan-out delivers those types to it: an `order.*` endpoint receives
 * `order.risk_status_changed` as surely as one subscribed to it by name.
 *
 * @internal
 */
final readonly class EventTypeAbilities
{
    public function __construct(
        private Settings $settings = new Settings,
    ) {}

    /**
     * The abilities a subscription to this event type takes.
     *
     * @return list<string>
     */
    public function required(string $eventType): array
    {
        $prefix = str_ends_with($eventType, '.*') ? substr($eventType, 0, -1) : null;
        $abilities = [];

        foreach ($this->settings->eventTypes() as $declared) {
            if ($declared !== $eventType && ($prefix === null || ! str_starts_with($declared, $prefix))) {
                continue;
            }

            $ability = $this->settings->abilityFor($declared);

            if ($ability !== null) {
                $abilities[] = $ability;
            }
        }

        return array_values(array_unique($abilities));
    }

    /**
     * Of these event types, the ones the user may not subscribe an endpoint to, in their order.
     * Without a user every type that takes an ability is refused.
     *
     * @param  array<array-key, string>  $eventTypes
     * @return list<string>
     */
    public function refused(array $eventTypes, ?Authenticatable $user): array
    {
        $refused = [];

        foreach ($eventTypes as $eventType) {
            foreach ($this->required($eventType) as $ability) {
                if (! $user instanceof Authenticatable || ! Gate::forUser($user)->allows($ability)) {
                    $refused[] = $eventType;

                    break;
                }
            }
        }

        return array_values(array_unique($refused));
    }
}
