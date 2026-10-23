<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Pushery\Webhooks\Client\InboundMessage;
use Pushery\Webhooks\Client\Models\WebhookCall;
use Pushery\Webhooks\Client\Tenancy\TenantScopes;
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
 * parent::__construct(). A failed() of its own declares its parameter as ?Throwable, the
 * signature Laravel documents, because PHP refuses a narrower one; it need not call
 * parent::failed(), because the queue's failure event records the call either way.
 *
 * The call of a config with a `tenant_scope` is read back from the queue, handled and recorded
 * inside the scope's run() for the call's tenant, so handle() runs in the tenant's context.
 */
class ProcessWebhookJob implements ShouldQueue
{
    use Queueable {
        __serialize as private serializeWithModels;
        __unserialize as private restoreWithModels;
    }

    /**
     * The key the queued payload names the call's config and tenant under, so the call can be read back in the
     * tenant's scope. No property can carry this name.
     */
    private const string TENANT_KEY = '@tenant';

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
     * Record that the call failed for good. The queue calls this after the last attempt, and
     * {@see RecordFailedCall} records the same once the queue's failure event follows.
     */
    public function failed(?Throwable $exception): void
    {
        RecordCallOutcome::record($this->webhookCall, WebhookCallStatus::Failed);
    }

    /**
     * The queued payload: the job's properties as the queue serializes them, the call as a reference to its row,
     * and the config and tenant the row is read back under.
     *
     * @return array<array-key, mixed>
     */
    public function __serialize(): array
    {
        /** @var array<array-key, mixed> $values the trait's __serialize() declares its array in a docblock alone */
        $values = $this->serializeWithModels();

        if ($this->webhookCall->tenant !== null) {
            $values[self::TENANT_KEY] = [$this->webhookCall->source, $this->webhookCall->tenant];
        }

        return $values;
    }

    /**
     * Restore the job from its queued payload, reading the call back in the scope of its tenant when its config
     * sets one.
     *
     * @param  array<array-key, mixed>  $values
     */
    public function __unserialize(array $values): void
    {
        $tenant = $values[self::TENANT_KEY] ?? null;

        if (! is_array($tenant) || ! is_string($tenant[0] ?? null) || ! is_string($tenant[1] ?? null)) {
            $this->restoreWithModels($values);

            return;
        }

        TenantScopes::runFor($tenant[0], $tenant[1], function () use ($values): void {
            $this->restoreWithModels($values);
        });
    }
}
