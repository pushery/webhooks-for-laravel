<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Pushery\Webhooks\Client\InboundMessage;
use Pushery\Webhooks\Client\Models\WebhookCall;
use Pushery\Webhooks\Client\WebhookCallStatus;
use Throwable;

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
 *
 * The stored call records the outcome: processed once handle() returns, failed once the
 * queue gives up on it after the last attempt. A status the handler writes itself stays, and a
 * call whose handler releases the job back onto the queue stays received until a later attempt
 * returns or the last one fails. A subclass with a constructor of its own calls
 * parent::__construct(), and one with a failed() of its own calls parent::failed(), or that half
 * of the record is lost.
 */
class ProcessWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public WebhookCall $webhookCall,
        public InboundMessage $message,
    ) {
        // The property rather than a middleware() method, so a subclass that defines middleware()
        // for its own purposes keeps this one: the queue runs both.
        $this->middleware = [new RecordCallOutcome];
    }

    public function handle(): void
    {
        // Override in your application to react to the stored call and its envelope.
    }

    /**
     * Record that the call failed for good. The queue calls this after the last attempt.
     */
    public function failed(?Throwable $exception): void
    {
        RecordCallOutcome::record($this->webhookCall, WebhookCallStatus::Failed);
    }
}
