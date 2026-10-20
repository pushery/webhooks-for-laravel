<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Exceptions;

use Pushery\Webhooks\WebhookManager;
use RuntimeException;

/**
 * The `after` handed to {@see WebhookManager::feed()} points before the delivery log's retention
 * window (`webhooks.platform.retention_months`). The deliveries between that point and the oldest
 * one the log keeps may be gone, so reading on would report a gap as if nothing had happened. The
 * receiver has to resynchronize from its own source and then read the feed without `after`. A 410
 * is the usual answer over HTTP.
 */
final class FeedCursorExpired extends RuntimeException
{
    public function __construct(public readonly int $retentionMonths)
    {
        parent::__construct(sprintf(
            'The feed cursor points before the %d month(s) the delivery log keeps; read the feed again from its start.',
            $retentionMonths,
        ));
    }
}
