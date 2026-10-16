<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Facades;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Facade;
use LogicException;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Erasure\ForgottenEvents;
use Pushery\Webhooks\Support\TenantIdentity;
use Pushery\Webhooks\Testing\FakedWebhook;
use Pushery\Webhooks\Testing\WebhookFake;
use Pushery\Webhooks\WebhookManager;

/**
 * The Platform layer's public API: register endpoints, fan an event out to every
 * matching subscription, run an endpoint's lifecycle (enable / disable / remove,
 * rotate its secret, ping it, replay one of its deliveries), and describe the event
 * catalog as an AsyncAPI document.
 *
 * In a test, `fake()` records every webhook the package would send instead of sending it,
 * and the assertions below read what it recorded.
 *
 * @method static WebhookSubscription subscribe(Model|TenantIdentity|null $owner, string $url, array<array-key, string> $eventTypes, ?string $name = null)
 * @method static void unsubscribe(WebhookSubscription $subscription)
 * @method static ForgottenEvents forgetEvents(array<array-key, string> $eventIds)
 * @method static WebhookSubscription enable(WebhookSubscription $subscription)
 * @method static WebhookSubscription disable(WebhookSubscription $subscription)
 * @method static Collection<int, WebhookDelivery> dispatch(string $eventType, array<array-key, mixed> $payload, ?Model $tenant = null)
 * @method static WebhookDelivery dispatchTo(WebhookSubscription $subscription, string $eventType, array<array-key, mixed> $payload)
 * @method static WebhookDelivery ping(WebhookSubscription $subscription)
 * @method static string rotateSecret(WebhookSubscription $subscription)
 * @method static bool revokeExpiredSecret(WebhookSubscription $subscription)
 * @method static WebhookDelivery redeliver(WebhookDelivery $delivery)
 * @method static array<string, mixed> asyncApi(?string $title = null, string $version = '1.0.0')
 *
 * @see WebhookManager
 */
final class Webhooks extends Facade
{
    /**
     * Record every webhook the package would send, for the rest of the test, instead of sending
     * it: no delivery-log row is written and no job is queued. Each sending method hands back the
     * deliveries it would have logged, unsaved.
     */
    public static function fake(): WebhookFake
    {
        $fake = new WebhookFake;

        Container::getInstance()->instance(WebhookFake::class, $fake);

        return $fake;
    }

    /**
     * @param  (Closure(FakedWebhook): bool)|null  $callback
     *
     * @see WebhookFake::assertDispatched()
     */
    public static function assertDispatched(string $eventType, ?Closure $callback = null): void
    {
        self::recorder()->assertDispatched($eventType, $callback);
    }

    /**
     * @see WebhookFake::assertDispatchedTimes()
     */
    public static function assertDispatchedTimes(string $eventType, int $times = 1): void
    {
        self::recorder()->assertDispatchedTimes($eventType, $times);
    }

    /**
     * @param  (Closure(FakedWebhook): bool)|null  $callback
     *
     * @see WebhookFake::assertNotDispatched()
     */
    public static function assertNotDispatched(string $eventType, ?Closure $callback = null): void
    {
        self::recorder()->assertNotDispatched($eventType, $callback);
    }

    /**
     * @see WebhookFake::assertNothingDispatched()
     */
    public static function assertNothingDispatched(): void
    {
        self::recorder()->assertNothingDispatched();
    }

    /**
     * @param  (Closure(FakedWebhook): bool)|null  $callback
     * @return BaseCollection<int, FakedWebhook>
     *
     * @see WebhookFake::dispatched()
     */
    public static function dispatched(?string $eventType = null, ?Closure $callback = null): BaseCollection
    {
        return self::recorder()->dispatched($eventType, $callback);
    }

    protected static function getFacadeAccessor(): string
    {
        return WebhookManager::class;
    }

    /**
     * The fake `fake()` switched on, which every assertion reads.
     *
     * @throws LogicException when the test switched no fake on
     */
    private static function recorder(): WebhookFake
    {
        return WebhookFake::active() ?? throw new LogicException('Call Webhooks::fake() before asserting on the webhooks it records.');
    }
}
