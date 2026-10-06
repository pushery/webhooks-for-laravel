<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client;

/**
 * How the bytes of an inbound delivery were read into {@see InboundMessage::$payload} —
 * or that nothing could read them.
 *
 * An empty payload alone can mean four things, and the one that matters is the one nobody
 * can see. A body no decoder understands arrives as `[]`, indistinguishable from a body that
 * genuinely carried nothing. A handler that finds no fields has nothing to do, the call is
 * marked processed and answered 200 — and the producer, told the delivery succeeded, never
 * sends it again. Nothing throws and nothing is logged, so the loss would be total and read as
 * success from both ends.
 *
 * {@see self::Unreadable} is the case worth branching on. It is the only one where the
 * delivery still means something that has not been read: the exact bytes are kept beside the
 * payload and `WebhookCall::body()` returns them — including when a large payload was offloaded
 * to a disk, where the column itself is null — so a handler that notices can still act on them.
 */
enum PayloadFormat: string
{
    case Json = 'json';

    case Form = 'form';

    case None = 'none';

    case Unreadable = 'unreadable';

    /**
     * Whether the payload is a complete view of what the producer sent.
     *
     * False for {@see self::Unreadable} alone. An absent body is readable: nothing was sent,
     * so nothing is missing — precisely the distinction a bare `$payload === []` cannot make.
     */
    public function readable(): bool
    {
        return $this !== self::Unreadable;
    }
}
