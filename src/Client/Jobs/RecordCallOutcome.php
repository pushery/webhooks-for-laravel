<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Jobs;

use Closure;
use Pushery\Webhooks\Client\Models\WebhookCall;
use Pushery\Webhooks\Client\Tenancy\TenantScopes;
use Pushery\Webhooks\Client\WebhookCallStatus;

/**
 * Records a handler's outcome on the call it handled: processed once handle() returns. A failure
 * is recorded by {@see RecordFailedCall} once the queue gives up after the last attempt; an
 * exception that passes through here ends only this attempt, and the queue may try again.
 *
 * A handler that released its job back onto the queue, or failed it, leaves the call to that
 * path, and a status the handler wrote itself stays: record() moves only a call still received.
 *
 * The handler of a call whose config sets a tenant scope runs inside the scope's run() for the
 * call's tenant, and so does the outcome recorded after it.
 *
 * @internal
 */
final class RecordCallOutcome
{
    public function handle(ProcessWebhookJob $job, Closure $next): mixed
    {
        return TenantScopes::run($job->webhookCall, static function () use ($job, $next): mixed {
            $result = $next($job);

            if ($job->job?->isReleased() !== true && $job->job?->hasFailed() !== true) {
                self::record($job->webhookCall, WebhookCallStatus::Processed);
            }

            return $result;
        });
    }

    /**
     * Move a stored call from received to the given outcome, and leave a call that is no longer
     * received as it is. Saved through the model, so its events fire and a searchable call's index
     * follows the status it carries. The call of a config with a tenant scope is read and saved in
     * the scope of its tenant.
     */
    public static function record(WebhookCall $call, WebhookCallStatus $outcome): void
    {
        TenantScopes::run($call, static function () use ($call, $outcome): void {
            $stored = $call->newQuery()
                ->whereKey($call->getKey())
                ->where('status', WebhookCallStatus::Received->value)
                ->first();

            if ($stored instanceof WebhookCall) {
                $stored->status = $outcome;
                $stored->save();
            }
        });
    }
}
