<?php

declare(strict_types=1);

namespace Webhooks\Enums;

/**
 * Lifecycle state of a single delivery-log entry.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Exhausted = 'exhausted';
}
