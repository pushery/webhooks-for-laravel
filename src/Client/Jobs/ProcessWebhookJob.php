<?php

declare(strict_types=1);

namespace Webhooks\Client\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Webhooks\Client\InboundMessage;
use Webhooks\Client\Models\WebhookCall;

/**
 * The base queued handler for a stored incoming webhook. Extend it in your app and
 * implement handle(); the stored row is available as $this->webhookCall (Eloquent
 * model) and the parsed envelope as $this->message (id/type/created_at/data). This
 * Job is the idiomatic home for your business logic — there is no service or action
 * layer between the request and here.
 *
 *     final class HandleStripeWebhook extends ProcessWebhookJob
 *     {
 *         public function handle(): void
 *         {
 *             match ($this->message->type) {
 *                 'invoice.paid' => ...,
 *                 default => ...,
 *             };
 *         }
 *     }
 *
 * Register the subclass as a config entry's 'process' value — a single class, or a
 * ['event.type' => Handler::class] map for per-type routing.
 */
class ProcessWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public WebhookCall $webhookCall,
        public InboundMessage $message,
    ) {}

    public function handle(): void
    {
        // Override in your application to react to the stored call and its envelope.
    }
}
