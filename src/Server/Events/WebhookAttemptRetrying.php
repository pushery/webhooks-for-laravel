<?php

declare(strict_types=1);

namespace Webhooks\Server\Events;

use Webhooks\Server\Data\WebhookDeliveryData;

/**
 * A failed attempt will be retried, carrying the backoff delay (seconds) before the
 * next one. Attempt-scoped: it fires once per retry that is scheduled, alongside the
 * {@see WebhookAttemptFailed} of the attempt that just failed.
 */
final readonly class WebhookAttemptRetrying
{
    public function __construct(
        public WebhookDeliveryData $data,
        public int $attempt,
        public int $delaySeconds,
    ) {}
}
