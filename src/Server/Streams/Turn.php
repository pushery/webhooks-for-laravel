<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Streams;

/**
 * Where a delivery stands in its stream at the moment its job runs.
 *
 * @internal
 */
enum Turn
{
    /** It is the oldest delivery of its stream, and it may go out. */
    case Mine;

    /** An older delivery of its stream has not succeeded yet. */
    case Waiting;

    /** It is the oldest delivery of its stream and ended without success; it goes out again only when it is resent. */
    case Held;

    /** It is no longer in its stream: it was skipped, so it is not sent. */
    case Gone;
}
