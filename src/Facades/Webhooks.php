<?php

declare(strict_types=1);

namespace Webhooks\Facades;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Webhooks\Models\WebhookDelivery;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Support\TenantIdentity;
use Webhooks\WebhookManager;

/**
 * The Platform layer's public API: register endpoints, fan an event out to every
 * matching subscription, and run an endpoint's lifecycle (enable / disable / remove,
 * rotate its secret, ping it, replay one of its deliveries).
 *
 * @method static WebhookSubscription subscribe(Model|TenantIdentity|null $owner, string $url, array<array-key, string> $eventTypes, ?string $name = null)
 * @method static void unsubscribe(WebhookSubscription $subscription)
 * @method static WebhookSubscription enable(WebhookSubscription $subscription)
 * @method static WebhookSubscription disable(WebhookSubscription $subscription)
 * @method static Collection<int, WebhookDelivery> dispatch(string $eventType, array<array-key, mixed> $payload, ?Model $tenant = null)
 * @method static WebhookDelivery ping(WebhookSubscription $subscription)
 * @method static string rotateSecret(WebhookSubscription $subscription)
 * @method static bool revokeExpiredSecret(WebhookSubscription $subscription)
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
