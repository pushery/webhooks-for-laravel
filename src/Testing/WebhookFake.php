<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Testing;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Testing\Fakes\Fake;
use PHPUnit\Framework\Assert;
use Pushery\Webhooks\Facades\Webhooks;

/**
 * Records every webhook the package would send, instead of sending it. A test switches it on with
 * {@see Webhooks::fake()}, and it stays on for the rest of that test.
 *
 * It sees every way the package sends: `dispatch()`, `dispatchTo()`, `ping()` and `redeliver()` on
 * the manager and the facade, `WebhookEvent::dispatch()`, a `PendingWebhook` sent by any of its
 * dispatch methods, and `DeliveryStreams::resend()`. Each call is recorded once, as a
 * {@see FakedWebhook}, and none of them writes a delivery-log row or queues a job.
 *
 * What runs before a send still runs. A payload its catalog schema refuses still throws,
 * `dispatchTo()` still refuses an endpoint that does not listen, and a test ping still counts
 * against its allowance.
 */
final class WebhookFake implements Fake
{
    /** @var list<FakedWebhook> */
    private array $recorded = [];

    /**
     * The fake the running test switched on, or null when it switched none on.
     *
     * @internal
     */
    public static function active(): ?self
    {
        $container = Container::getInstance();

        if ($container->bound(self::class)) {
            return $container->make(self::class);
        }

        return null;
    }

    /**
     * Record a webhook in place of sending it.
     *
     * @internal
     */
    public function record(FakedWebhook $webhook): void
    {
        $this->recorded[] = $webhook;
    }

    /**
     * Assert that a webhook of the event type was dispatched, and, given a callback, that one of
     * them passes it.
     *
     * @param  (Closure(FakedWebhook): bool)|null  $callback
     */
    public function assertDispatched(string $eventType, ?Closure $callback = null): void
    {
        Assert::assertTrue(
            $this->dispatched($eventType, $callback)->isNotEmpty(),
            "The expected [{$eventType}] webhook was not dispatched.",
        );
    }

    /**
     * Assert that webhooks of the event type were dispatched exactly so many times.
     */
    public function assertDispatchedTimes(string $eventType, int $times = 1): void
    {
        $count = $this->dispatched($eventType)->count();

        Assert::assertSame(
            $times,
            $count,
            "The expected [{$eventType}] webhook was dispatched {$count} times instead of {$times} times.",
        );
    }

    /**
     * Assert that no webhook of the event type was dispatched, or, given a callback, that none of
     * them passes it.
     *
     * @param  (Closure(FakedWebhook): bool)|null  $callback
     */
    public function assertNotDispatched(string $eventType, ?Closure $callback = null): void
    {
        Assert::assertTrue(
            $this->dispatched($eventType, $callback)->isEmpty(),
            "The unexpected [{$eventType}] webhook was dispatched.",
        );
    }

    /**
     * Assert that no webhook was dispatched at all, by any way of sending.
     */
    public function assertNothingDispatched(): void
    {
        $types = array_map(
            static fn (FakedWebhook $webhook): string => $webhook->eventType ?? 'a webhook without an event type',
            $this->recorded,
        );

        Assert::assertTrue(
            $types === [],
            'No webhook was expected, and these were dispatched: '.implode(', ', $types).'.',
        );
    }

    /**
     * The webhooks that were dispatched, in the order they were, of one event type or of every
     * type, and, given a callback, only those that pass it.
     *
     * @param  (Closure(FakedWebhook): bool)|null  $callback
     * @return Collection<int, FakedWebhook>
     */
    public function dispatched(?string $eventType = null, ?Closure $callback = null): Collection
    {
        return new Collection($this->recorded)
            ->filter(static fn (FakedWebhook $webhook): bool => ($eventType === null || $webhook->eventType === $eventType)
                && (! $callback instanceof Closure || $callback($webhook)))
            ->values();
    }
}
