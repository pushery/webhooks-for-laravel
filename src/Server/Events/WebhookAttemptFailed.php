<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Events;

use Pushery\Webhooks\Core\Http\TransportResponse;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Throwable;

/**
 * ONE HTTP attempt failed (a non-2xx response, or a thrown transport error) and the
 * delivery is NOT finished: it fires once per failed attempt and the delivery will be
 * retried. Carries whichever of the response / exception applies.
 *
 * This is NOT the event to notify an endpoint owner from — it fires on every attempt,
 * so a notification wired here goes out once per retry. The delivery gives up exactly
 * once, and announces it as {@see WebhookAttemptsExhausted} (transport-level) and
 * `Pushery\Webhooks\Events\WebhookDeliveryFailed` (domain-level, carrying the model).
 */
final readonly class WebhookAttemptFailed
{
    public function __construct(
        public WebhookDeliveryData $data,
        public int $attempt,
        public ?TransportResponse $response = null,
        public ?Throwable $exception = null,
    ) {}
}
