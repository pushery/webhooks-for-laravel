<?php

declare(strict_types=1);

namespace Webhooks\Server\Exceptions;

use RuntimeException;
use Webhooks\Core\Http\Exceptions\NonRetryable;

/**
 * A queued delivery was refused before it went out — its endpoint was switched off or
 * deleted while the delivery sat in the queue. It is non-retryable by definition: the
 * decision not to send is the outcome, not a transport failure to try again.
 */
final class DeliveryRefused extends RuntimeException implements NonRetryable
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
