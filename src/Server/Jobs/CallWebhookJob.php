<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Jobs;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Pushery\Webhooks\Core\Http\TransportResponse;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Pushery\Webhooks\Server\Delivery\DeliveryGate;
use Pushery\Webhooks\Server\Delivery\DeliveryPipeline;
use Pushery\Webhooks\Server\Delivery\Disposition;
use Pushery\Webhooks\Server\Delivery\RetryAfter;
use Pushery\Webhooks\Server\Events\WebhookAttemptDeferred;
use Pushery\Webhooks\Server\Events\WebhookAttemptFailed;
use Pushery\Webhooks\Server\Events\WebhookAttemptRetrying;
use Pushery\Webhooks\Server\Events\WebhookAttemptsExhausted;
use Pushery\Webhooks\Server\Events\WebhookAttemptStarting;
use Pushery\Webhooks\Server\Events\WebhookAttemptSucceeded;
use Pushery\Webhooks\Server\Exceptions\DeliveryRefused;
use Pushery\Webhooks\Server\Exceptions\QueueCannotRetry;
use Throwable;

/**
 * The queued delivery job. It is a THIN wrapper over the {@see DeliveryPipeline}:
 * the pipeline does one attempt and returns a typed outcome; the job translates
 * that outcome into lifecycle events and queue control (retry via release(), or a
 * final-failure event). It never throws for a classified outcome, so the events are
 * the single source of truth for a delivery's fate — a no-retry 4xx is a clean final
 * failure, not a queue exception.
 *
 * No path may end without a terminal event. The pipeline turns every transport error
 * into an outcome, {@see self::failed()} catches anything that could still kill the
 * job (a worker timeout, an unexpected exception), and {@see self::backoff()} makes
 * even that death respect the configured schedule instead of re-releasing instantly.
 * A log row left at "pending" for ever, and a dead endpoint hammered with zero delay,
 * are the two failures that observability itself cannot see.
 *
 * The message id is stable across attempts (re-signed at send time inside the
 * pipeline), so the receiver dedupes an at-least-once retry.
 */
final class CallWebhookJob implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Headroom over the HTTP budget: connect + response timeout, plus the time the
     * signing, DNS resolution and event handling around them can take.
     */
    public const int TIMEOUT_HEADROOM = 10;

    /**
     * The floor under the derived timeout.
     *
     * Public alongside the headroom, so {@see JobTimeoutBudget} applies this arithmetic rather than
     * a copy of it. The check it performs is whether this timeout can reach the queue's
     * `retry_after` — a number written twice would make it quietly wrong in the direction that
     * reports nothing.
     */
    public const int MINIMUM_TIMEOUT = 30;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly WebhookDeliveryData $data,
    ) {
        $this->tries = max(1, $data->maxTries);

        // The JOB timeout must always sit above the HTTP timeout it wraps, or the worker
        // kills the job mid-request: no lifecycle event fires, the delivery row is left
        // pending for ever and the retry budget is never honored. Raising
        // webhooks.server.timeout is a perfectly reasonable thing to do for a slow
        // consumer, so it must not be able to overtake a hard-coded ceiling.
        $this->timeout = max(
            self::MINIMUM_TIMEOUT,
            $data->options->connectTimeout + $data->options->timeout + self::TIMEOUT_HEADROOM,
        );
    }

    public function handle(DeliveryPipeline $pipeline, DeliveryGate $gate): void
    {
        $attempt = $this->attempt();

        // Re-check the destination the moment before it is sent, not only when it was
        // enqueued: an endpoint that has since been disabled (by the circuit breaker or
        // by its tenant) or deleted outright must not receive the backlog that was
        // already in flight for it.
        $refusal = $gate->refusalFor($this->data);

        if ($refusal !== null) {
            $this->finalFailure($attempt, null, DeliveryRefused::because($refusal));

            return;
        }

        event(new WebhookAttemptStarting($this->data, $attempt));

        $outcome = $pipeline->attempt($this->data);

        if ($outcome->disposition === Disposition::Succeeded && $outcome->response instanceof TransportResponse) {
            event(new WebhookAttemptSucceeded($this->data, $attempt, $outcome->response));

            return;
        }

        event(new WebhookAttemptFailed($this->data, $attempt, $outcome->response, $outcome->exception));

        if ($outcome->disposition !== Disposition::Retryable) {
            event(new WebhookAttemptsExhausted($this->data, $attempt, $outcome->response, $outcome->exception));

            return;
        }

        $hint = $this->retryAfterHint($outcome->response);

        // The endpoint asked us to come back later than the queue can hold a job for.
        // Waiting the cap and CHARGING the attempt would be the worst of both: we return
        // while it is still rate-limiting us, and the delivery is exhausted long before
        // its window elapses. Wait the cap, and do not charge it — bounded, so a
        // permanently rate-limiting endpoint still terminates.
        // The zero cap is excluded here for the same reason the schedule excludes it: it means
        // the hint is switched off, so there is no wait to defer INTO. Without that test every
        // hint exceeds a cap of zero, and each deferral re-dispatches the job with a delay of
        // the cap — zero — so the delivery fires retryAfterMaxDeferrals immediate requests at
        // the endpoint that asked for quiet, on top of its ordinary retry budget.
        if ($hint !== null && $this->data->options->retryAfterCap > 0
            && $hint > $this->data->options->retryAfterCap && $this->canDefer()) {
            $this->defer($attempt, $hint);

            return;
        }

        if ($this->budgetRemaining($attempt) && $this->canRelease()) {
            $delay = $this->data->backoff->delayAfterAttempt($attempt, $hint);
            event(new WebhookAttemptRetrying($this->data, $attempt, $delay));
            $this->release($delay);

            return;
        }

        // Either the budget is spent, or the queue cannot carry a retry at all. The second
        // case has to say so: without it, a retryable failure on the sync connection fired
        // WebhookAttemptRetrying and then NOTHING — no terminal event, no failed(), and a
        // delivery row left at a non-final state for ever, which is the one failure
        // observability cannot see. This class promises that cannot happen; it now keeps it.
        event(new WebhookAttemptsExhausted(
            $this->data,
            $attempt,
            $outcome->response,
            $this->canRelease() ? $outcome->exception : QueueCannotRetry::onSyncConnection($outcome->exception),
        ));
    }

    /**
     * The delay schedule the WORKER uses when an unexpected exception escapes handle()
     * — which, without it, would re-release the job with no delay at all and hammer a
     * failing endpoint `tries` times back-to-back. The job's own retries go through
     * release() with the same strategy; this covers the path it does not control.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return $this->data->backoff->schedule($this->tries);
    }

    /**
     * The last backstop: whatever killed the job — a worker timeout, an unexpected
     * exception, a serialization failure — the delivery reaches a terminal state and
     * says why. Nothing may leave a delivery row pending for ever.
     */
    public function failed(?Throwable $exception): void
    {
        event(new WebhookAttemptsExhausted($this->data, $this->attempt(), null, $exception));
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->data->tags;
    }

    /**
     * The number of requests this delivery has made, counting the ones made by the jobs
     * that preceded a Retry-After deferral (a re-dispatched job's own attempt counter
     * starts over).
     */
    private function attempt(): int
    {
        return $this->attempts() + $this->data->attemptOffset;
    }

    /**
     * Whether the delivery may make another attempt. Attempts spent waiting out an
     * endpoint's own rate-limit window are not charged to the budget: the endpoint told
     * us when to come back, and honoring that is not a failed try of ours.
     */
    private function budgetRemaining(int $attempt): bool
    {
        return $attempt - $this->data->retryAfterDeferrals < $this->tries;
    }

    /**
     * Whether releasing this job actually re-queues it.
     *
     * Only the sync connection is asked about, and a null job deliberately is NOT: a job
     * with no queue context is one being driven directly, which is a test or a manual call
     * rather than anything a host deploys. Treating that as terminal would change behavior
     * on a path production never takes.
     */
    private function canRelease(): bool
    {
        return ! $this->job instanceof SyncJob;
    }

    private function canDefer(): bool
    {
        return $this->data->retryAfterDeferrals < $this->data->options->retryAfterMaxDeferrals
            && $this->queueCanDelay();
    }

    /**
     * Whether the connection a deferral would land on can actually hold it back.
     *
     * The connection rather than `$this->job`, and the difference is why this is a second method
     * and not a call to {@see self::canRelease()}. A deferral is not a release: it dispatches a
     * fresh job, so the question is what the target connection does with a delay, and
     * `SyncQueue::later()` ignores its `$delay` argument outright and calls `push()`.
     *
     * Without this, a 429 asking for longer than the cap re-ran the delivery immediately, and
     * did so once per remaining deferral. Measured on the sync connection with the shipped
     * defaults and a `Retry-After: 3600`: SEVEN real requests, in milliseconds, at the endpoint
     * that had just asked for an hour of quiet — the initial attempt plus all six deferrals.
     *
     * The code two branches up already warns about exactly this shape for a zero cap and
     * excludes it. The same thing happens on `sync` at ANY cap, because there the delay is not
     * short — it is not honored at all.
     */
    private function queueCanDelay(): bool
    {
        return ! app(QueueManager::class)->connection($this->connection) instanceof SyncQueue;
    }

    /**
     * Continue the delivery in a fresh job after the Retry-After cap, carrying the
     * attempts made so far and one more deferral. A release() cannot be used: it
     * re-pushes the ORIGINAL payload, so the counters would be lost.
     */
    private function defer(int $attempt, int $requested): void
    {
        $delay = $this->data->options->retryAfterCap;

        event(new WebhookAttemptDeferred($this->data, $attempt, $delay, $requested));

        $job = new self($this->data->deferred($attempt));
        $job->onQueue($this->queue);
        $job->onConnection($this->connection);
        $job->delay($delay);

        dispatch($job);
    }

    private function finalFailure(int $attempt, ?TransportResponse $response, Throwable $exception): void
    {
        event(new WebhookAttemptFailed($this->data, $attempt, $response, $exception));
        event(new WebhookAttemptsExhausted($this->data, $attempt, $response, $exception));
    }

    /**
     * The Retry-After hint to hand the backoff strategy: the parsed seconds when the
     * feature is enabled and the endpoint answered a retryable 429/503 with a
     * Retry-After header, otherwise null so the strategy uses its jittered schedule.
     */
    private function retryAfterHint(?TransportResponse $response): ?int
    {
        if (! $this->data->options->respectRetryAfter || ! $response instanceof TransportResponse) {
            return null;
        }

        if ($response->status !== 429 && $response->status !== 503) {
            return null;
        }

        return RetryAfter::parse($this->retryAfterHeader($response));
    }

    /**
     * The response's Retry-After header value, matched case-insensitively per RFC
     * 9110, or null when the endpoint sent none.
     */
    private function retryAfterHeader(TransportResponse $response): ?string
    {
        foreach ($response->headers as $name => $values) {
            if (strtolower($name) === 'retry-after') {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
