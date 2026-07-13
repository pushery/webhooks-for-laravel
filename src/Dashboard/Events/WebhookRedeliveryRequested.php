<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Webhooks\Models\WebhookDelivery;

/**
 * Raised from a dashboard panel when an operator asks to replay a delivery. The
 * dashboard never talks to the delivery engine directly: it announces intent and a
 * listener carries it out, so the replay path stays a single, testable hop that a
 * host can also hook (audit log, extra authorization) without touching the UI.
 */
final readonly class WebhookRedeliveryRequested
{
    use Dispatchable;

    public function __construct(
        public WebhookDelivery $delivery,
    ) {}
}
