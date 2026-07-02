<?php

declare(strict_types=1);

namespace Webhooks;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Webhooks\Models\WebhookDelivery;

/**
 * Ergonomic entry point for emitting a domain event to its subscribers:
 *
 *     WebhookEvent::dispatch('invoice.paid', $payload, tenant: $team);
 */
final class WebhookEvent
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @return Collection<int, WebhookDelivery>
     */
    public static function dispatch(string $eventType, array $payload, ?Model $tenant = null): Collection
    {
        return app(WebhookManager::class)->dispatch($eventType, $payload, $tenant);
    }
}
