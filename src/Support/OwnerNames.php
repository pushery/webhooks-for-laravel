<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Pushery\Webhooks\Models\WebhookSubscription;

/**
 * The name the operator console shows for the owner of an endpoint.
 *
 * An owner is a morph, so no column can be assumed: by default an owner is named by a non-empty
 * `name` attribute, and otherwise by its model and key, such as `Team #12`. A host whose owners carry
 * their name elsewhere registers its own resolver with resolveUsing(), from a service provider's
 * boot(). An endpoint without an owner is global and receives the events of every owner, so it has
 * no name here, and the console marks it instead.
 */
final class OwnerNames
{
    /**
     * @var (Closure(Model): string)|null
     */
    private static ?Closure $resolver = null;

    /**
     * Name an owner the host's way.
     *
     * @param  Closure(Model): string  $resolver
     */
    public static function resolveUsing(Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Drop a registered resolver and fall back to the default.
     */
    public static function forget(): void
    {
        self::$resolver = null;
    }

    /**
     * The name of an owner.
     */
    public static function for(Model $owner): string
    {
        if (self::$resolver instanceof Closure) {
            return (self::$resolver)($owner);
        }

        $name = $owner->getAttribute('name');

        if (is_string($name) && trim($name) !== '') {
            return $name;
        }

        return class_basename($owner).' #'.self::key($owner->getKey());
    }

    /**
     * The name of an endpoint's owner, or null for a global endpoint, which has none. An owner whose
     * row is gone is named by the type and key the endpoint still holds, so it does not read as global.
     */
    public static function ofEndpoint(WebhookSubscription $subscription): ?string
    {
        $owner = $subscription->owner;

        if ($owner instanceof Model) {
            return self::for($owner);
        }

        $type = $subscription->getAttribute('owner_type');

        if (! is_string($type) || $type === '') {
            return null;
        }

        return class_basename($type).' #'.self::key($subscription->getAttribute('owner_id'));
    }

    /**
     * The owner's key as the row holds it, or a question mark where it is neither a number nor a string.
     *
     * @param  mixed  $key  the `owner_id` attribute, an integer or a string by the column's type, or whatever a host's cast made of it
     */
    private static function key(mixed $key): string
    {
        return is_int($key) || is_string($key) ? (string) $key : '?';
    }
}
