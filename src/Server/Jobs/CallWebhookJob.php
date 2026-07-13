<?php

declare(strict_types=1);

namespace Webhooks\Server\Jobs;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;
use Webhooks\Core\Http\TransportResponse;
use Webhooks\Server\Data\WebhookDeliveryData;
use Webhooks\Server\Delivery\DeliveryGate;
use Webhooks\Server\Delivery\DeliveryPipeline;
use Webhooks\Server\Delivery\Disposition;
use Webhooks\Server\Delivery\RetryAfter;
use Webhooks\Server\Events\WebhookAttemptDeferred;
use Webhooks\Server\Events\WebhookAttemptFailed;
use Webhooks\Server\Events\WebhookAttemptRetrying;
use Webhooks\Server\Events\WebhookAttemptsExhausted;
use Webhooks\Server\Events\WebhookAttemptStarting;
use Webhooks\Server\Events\WebhookAttemptSucceeded;
use Webhooks\Server\Exceptions\DeliveryRefused;

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
    private const int TIMEOUT_HEADROOM = 10;

    private const int MINIMUM_TIMEOUT = 30;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly WebhookDeliveryData $data,
    ) {
        $this->tries = max(1, $data->maxTries);

        // The JOB timeout must always sit above the HTTP timeout it wraps, or the worker
        // kills the job mid-request: no lifecycle event fires, the delivery row is left
        // pending for ever and the retry budget is never honoured. Raising
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
        if ($hint !== null && $hint > $this->data->options->retryAfterCap && $this->canDefer()) {
            $this->defer($attempt, $hint);

            return;
        }

        if ($this->budgetRemaining($attempt)) {
            $delay = $this->data->backoff->delayAfterAttempt($attempt, $hint);
            event(new WebhookAttemptRetrying($this->data, $attempt, $delay));
            $this->release($delay);

            return;
        }

        event(new WebhookAttemptsExhausted($this->data, $attempt, $outcome->response, $outcome->exception));
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
     * us when to come back, and honouring that is not a failed try of ours.
     */
    private function budgetRemaining(int $attempt): bool
    {
        return $attempt - $this->data->retryAfterDeferrals < $this->tries;
    }

    private function canDefer(): bool
    {
        return $this->data->retryAfterDeferrals < $this->data->options->retryAfterMaxDeferrals;
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
