<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Events;

use Pushery\Webhooks\Core\Http\TransportResponse;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Throwable;

/**
 * The delivery is over and it never landed: its attempts are spent, or one of them hit
 * a non-retryable failure (a blocked destination, a no-retry 4xx). Fires exactly ONCE
 * per delivery, no matter how many attempts it took — the transport-level place to
 * react to a dead endpoint. The Platform circuit breaker listens here to count
 * consecutive failures and auto-disable an endpoint.
 *
 * A consumer that runs the Platform layer can listen to
 * `Pushery\Webhooks\Events\WebhookDeliveryFailed` instead, which fires at the same moment and
 * carries the WebhookDelivery model rather than the transport's value object.
 */
final readonly class WebhookAttemptsExhausted
{
    public function __construct(
        public WebhookDeliveryData $data,
        public int $attempt,
        public ?TransportResponse $response = null,
        public ?Throwable $exception = null,
    ) {}
}
