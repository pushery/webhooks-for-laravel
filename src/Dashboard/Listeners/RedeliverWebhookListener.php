<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Listeners;

use Webhooks\Dashboard\Events\WebhookRedeliveryRequested;
use Webhooks\WebhookManager;

/**
 * Carries out a requested redelivery by handing the original log entry back to the
 * core engine. The replay business logic lives here — not in a service layer — so
 * the dashboard panel that raised the event stays a thin, presentation-only shell.
 *
 * @internal
 */
final readonly class RedeliverWebhookListener
{
    public function __construct(
        private WebhookManager $manager,
    ) {}

    public function handle(WebhookRedeliveryRequested $event): void
    {
        $this->manager->redeliver($event->delivery);
    }
}
