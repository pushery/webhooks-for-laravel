<?php

declare(strict_types=1);

namespace Webhooks\Server\Events;

use Webhooks\Server\Data\WebhookDeliveryData;

/**
 * A rate-limiting endpoint asked to be retried later than the queue can hold a job for
 * (webhooks.server.backoff.retry_after_cap). The next attempt comes back at the cap
 * instead, and this wait is NOT charged against the delivery's retry budget — so an
 * endpoint answering "429, Retry-After: 3600" is still there to receive the webhook
 * when its window elapses, rather than having exhausted it half an hour earlier.
 *
 * Carries both what the endpoint asked for and what was actually waited, so an operator
 * can see the gap and raise the cap on a queue that can hold it.
 */
final readonly class WebhookAttemptDeferred
{
    public function __construct(
        public WebhookDeliveryData $data,
        public int $attempt,
        public int $delaySeconds,
        public int $requestedSeconds,
    ) {}
}
