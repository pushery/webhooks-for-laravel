<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Exceptions;

use LogicException;

/**
 * An ordered delivery was dispatched where it cannot keep its order.
 *
 * Thrown when the delivery is dispatched, before anything is queued or sent, because each case
 * is a matter of configuration that would otherwise surface later as a stream that never moves.
 */
final class OrderedDeliveryUnavailable extends LogicException
{
    public static function notEnabled(): self
    {
        return new self(
            'Ordered delivery keeps its streams in the webhook_delivery_streams table, which the Server layer '
            .'creates while webhooks.server.ordering.enabled is true. Turn that on and migrate, then dispatch again.',
        );
    }

    public static function dispatchedSync(): self
    {
        return new self(
            'An ordered delivery waits in the queue until the deliveries before it in its stream have succeeded, '
            .'and dispatchSync() sends on the current process without a queue. Use dispatch().',
        );
    }

    public static function queueCannotWait(string $connection): self
    {
        return new self(
            "An ordered delivery waits in the queue until the deliveries before it in its stream have succeeded, and the queue connection `{$connection}` "
            .'cannot hold a job back: the sync connection runs it at once, and an SQS FIFO queue takes a delay only for the whole queue. '
            .'Send ordered deliveries on a connection such as database or redis.',
        );
    }
}
