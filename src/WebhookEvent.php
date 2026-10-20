<?php

declare(strict_types=1);

namespace Pushery\Webhooks;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Pushery\Webhooks\Models\WebhookDelivery;

/**
 * Ergonomic entry point for emitting a domain event to its subscribers:
 *
 *     WebhookEvent::dispatch('invoice.paid', $payload, tenant: $team);
 *
 * An event that already has an id in the host's own records keeps it:
 *
 *     WebhookEvent::dispatch('invoice.paid', $payload, tenant: $team, eventId: $event->id);
 */
final class WebhookEvent
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @return Collection<int, WebhookDelivery>
     */
    public static function dispatch(string $eventType, array $payload, ?Model $tenant = null, ?string $eventId = null): Collection
    {
        return app(WebhookManager::class)->dispatch($eventType, $payload, $tenant, $eventId);
    }
}
