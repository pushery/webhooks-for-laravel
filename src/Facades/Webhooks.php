<?php

declare(strict_types=1);

namespace Webhooks\Facades;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Webhooks\Models\WebhookDelivery;
use Webhooks\Models\WebhookSubscription;
use Webhooks\WebhookManager;

/**
 * @method static WebhookSubscription subscribe(?Model $owner, string $url, array<array-key, string> $eventTypes, ?string $name = null)
 * @method static Collection<int, WebhookDelivery> dispatch(string $eventType, array<array-key, mixed> $payload, ?Model $tenant = null)
 * @method static WebhookDelivery ping(WebhookSubscription $subscription)
 * @method static WebhookDelivery redeliver(WebhookDelivery $delivery)
 *
 * @see WebhookManager
 */
final class Webhooks extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WebhookManager::class;
    }
}
