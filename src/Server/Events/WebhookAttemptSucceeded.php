<?php

declare(strict_types=1);

namespace Webhooks\Server\Events;

use Webhooks\Core\Http\TransportResponse;
use Webhooks\Server\Data\WebhookDeliveryData;

/**
 * ONE HTTP attempt returned a 2xx. Carries the captured response so a listener can
 * record duration_ms, the status and a response snapshot.
 *
 * Attempt-scoped, like every event in this namespace. The once-per-delivery domain
 * counterpart, which carries the WebhookDelivery model and is only dispatched while the
 * Platform layer is enabled, is `Webhooks\Events\WebhookDeliverySucceeded`.
 */
final readonly class WebhookAttemptSucceeded
{
    public function __construct(
        public WebhookDeliveryData $data,
        public int $attempt,
        public TransportResponse $response,
    ) {}
}
