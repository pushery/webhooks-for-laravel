<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Events;

use Pushery\Webhooks\Server\Data\WebhookDeliveryData;

/**
 * ONE HTTP attempt is about to be made: fired inside the delivery job, immediately
 * before the request goes out — and fired again for every retry of the same delivery.
 *
 * Every event in this namespace is scoped to a single ATTEMPT of the transport. A
 * delivery's final, once-per-delivery domain outcome lives in the Pushery\Webhooks\Events
 * family instead — `Pushery\Webhooks\Events\WebhookDeliverySucceeded` and
 * `Pushery\Webhooks\Events\WebhookDeliveryFailed`, which carry the delivery model.
 */
final readonly class WebhookAttemptStarting
{
    public function __construct(
        public WebhookDeliveryData $data,
        public int $attempt,
    ) {}
}
