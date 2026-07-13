<?php

declare(strict_types=1);

namespace Webhooks\Server\Delivery;

/**
 * How a delivery attempt's outcome is treated: a success, a retryable failure
 * (drives the backoff), or a final failure (no more attempts).
 *
 * @internal
 */
enum Disposition
{
    case Succeeded;

    case Retryable;

    case FinalFailure;
}
