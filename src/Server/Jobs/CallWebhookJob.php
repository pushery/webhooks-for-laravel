<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Jobs;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SqsQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Date;
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
use Pushery\Webhooks\Server\Streams\StreamLedger;
use Pushery\Webhooks\Server\Streams\Turn;
use Throwable;

/**
 * The queued delivery job. It is a thin wrapper over the {@see DeliveryPipeline}:
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
 *
 * A delivery with an ordering key goes out only when it is the oldest delivery of its stream
 * that has not succeeded; until then its job comes back after a growing wait, which costs
 * none of its tries. Anything but a success holds the stream at the delivery, so the ones
 * behind it wait until it is resent or skipped through `Pushery\Webhooks\Server\DeliveryStreams`.
 * A delivery with a time to keep trying retries until that time is up, however many tries
 * that takes.
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

    /**
     * The longest message timer SQS accepts, in seconds. AWS refuses a longer `DelaySeconds`, and
     * Laravel's `SqsQueue` hands a job's delay to it unchanged.
     */
    private const int SQS_MAX_DELAY_SECONDS = 900;

    /**
     * The longest a delivery waits before it looks again whether its turn in the stream has
     * come, in seconds. The first wait is a second, and each one after it twice as long.
     */
    public const int LONGEST_TURN_WAIT = 60;

    /**
     * How long past a delivery's time to keep trying the queue still hands its job to a worker,
     * in seconds. The last retry is scheduled for that moment itself, and a worker that picks it
     * up a little late must still run it rather than fail the job unseen.
     */
    public const int DEADLINE_GRACE = 3600;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly WebhookDeliveryData $data,
    ) {
        $this->tries = max(1, $data->maxTries);

        // The job timeout must always sit above the HTTP timeout it wraps, or the worker
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
        $stream = $this->data->stream();

        if ($stream !== null) {
            $turn = app(StreamLedger::class)->turnOf($stream, $this->data->messageId);

            if ($turn === Turn::Waiting) {
                $this->waitForTurn();

                return;
            }

            // A held delivery ended already and said so; it goes out again only after a resend,
            // which lifts the hold before it queues the delivery.
            if ($turn === Turn::Held) {
                return;
            }

            if ($turn === Turn::Gone) {
                $this->finalFailure($attempt, null, DeliveryRefused::because(
                    'The delivery was taken out of its ordered stream before its turn came, so it was not sent.',
                ));

                return;
            }
        }

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
            $this->leaveStream();

            event(new WebhookAttemptSucceeded($this->data, $attempt, $outcome->response));

            return;
        }

        event(new WebhookAttemptFailed($this->data, $attempt, $outcome->response, $outcome->exception));

        if ($outcome->disposition !== Disposition::Retryable) {
            $this->holdStream();

            event(new WebhookAttemptsExhausted($this->data, $attempt, $outcome->response, $outcome->exception));

            return;
        }

        $hint = $this->retryAfterHint($outcome->response);

        // The endpoint asked us to come back later than the queue can hold a job for.
        // Waiting the cap and charging the attempt would be the worst of both: we return
        // while it is still rate-limiting us, and the delivery is exhausted long before
        // its window elapses. Wait the cap, and do not charge it — bounded, so a
        // permanently rate-limiting endpoint still terminates.
        // The zero cap is excluded here for the same reason the schedule excludes it: it means
        // the hint is switched off, so there is no wait to defer INTO. Without that test every
        // hint exceeds a cap of zero, and each deferral re-dispatches the job with a delay of
        // the cap — zero — so the delivery fires retryAfterMaxDeferrals immediate requests at
        // the endpoint that asked for quiet, on top of its ordinary retry budget.
        //
        // A delivery with a time to keep trying has no budget of tries to protect, so it never
        // defers: its next attempt waits the cap like any other retry, within that time.
        if ($hint !== null && $this->data->options->retryAfterCap > 0 && $this->data->retryUntil() === null
            && $hint > $this->data->options->retryAfterCap && $this->canDefer()) {
            $this->defer($attempt, $hint);

            return;
        }

        if ($this->budgetRemaining($attempt) && $this->canRelease()) {
            $delay = $this->withinDeadline($this->data->backoff->delayAfterAttempt($attempt, $hint));
            event(new WebhookAttemptRetrying($this->data, $attempt, $delay));
            $this->release($delay);

            return;
        }

        $this->holdStream();

        // Either the budget is spent, or the queue cannot carry a retry at all. The second
        // case has to say so: without it, a retryable failure on the sync connection fired
        // WebhookAttemptRetrying and then nothing — no terminal event, no failed(), and a
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
     * The delay schedule the worker uses when an unexpected exception escapes handle()
     * — which, without it, would re-release the job with no delay at all and hammer a
     * failing endpoint `tries` times back-to-back. The job's own retries go through
     * release() with the same strategy; this covers the path it does not control.
     *
     * A delivery with a time to keep trying is retried by the worker past its tries, until
     * that time is up, and the worker repeats the schedule's last delay for every retry the
     * schedule does not name. So such a delivery always gets at least one delay, even with a
     * single try, where the schedule would otherwise be empty and each retry immediate.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return $this->data->backoff->schedule($this->data->retryUntil() === null ? $this->tries : max(2, $this->tries));
    }

    /**
     * The last backstop: whatever killed the job — a worker timeout, an unexpected
     * exception, a serialization failure — the delivery reaches a terminal state and
     * says why. Nothing may leave a delivery row pending for ever.
     */
    public function failed(?Throwable $exception): void
    {
        $this->holdStream();

        event(new WebhookAttemptsExhausted($this->data, $this->attempt(), null, $exception));
    }

    /**
     * The moment the queue stops handing this job to a worker, for a delivery with a time to
     * keep trying: that time, plus {@see self::DEADLINE_GRACE}. Laravel reads it when the job is
     * queued and, while it lies ahead, retries the job however often it has run, so the job's
     * own count of tries plays no part. Null for a delivery that counts its tries.
     */
    public function retryUntil(): ?int
    {
        $deadline = $this->data->retryUntil();

        return $deadline === null ? null : $deadline + self::DEADLINE_GRACE;
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
     * us when to come back, and honoring that is not a failed try of ours. A delivery with
     * a time to keep trying has no budget of tries at all: it may until that time is up.
     */
    private function budgetRemaining(int $attempt): bool
    {
        $deadline = $this->data->retryUntil();

        if ($deadline !== null) {
            return Date::now()->getTimestamp() < $deadline;
        }

        return $attempt - $this->data->retryAfterDeferrals < $this->tries;
    }

    /**
     * The delay before the next attempt, shortened so that the attempt still falls within the
     * delivery's time to keep trying. The last attempt then lands on that moment, rather than
     * the schedule stepping past it and giving the delivery up an interval early.
     */
    private function withinDeadline(int $delay): int
    {
        $deadline = $this->data->retryUntil();

        if ($deadline === null) {
            return $delay;
        }

        return max(1, min($delay, $deadline - Date::now()->getTimestamp()));
    }

    /**
     * Come back once an older delivery of the stream may have succeeded: a fresh job, so the
     * wait costs none of the delivery's tries, after a second, then twice as long each time, up
     * to {@see self::LONGEST_TURN_WAIT}.
     */
    private function waitForTurn(): void
    {
        $delay = min(self::LONGEST_TURN_WAIT, 2 ** min($this->data->streamWaits(), 6));

        $job = new self($this->data->waited($delay));
        $job->onQueue($this->queue);
        $job->onConnection($this->connection);
        $job->delay($delay);

        dispatch($job);
    }

    /**
     * The delivery succeeded, so the next one of its stream may go out.
     */
    private function leaveStream(): void
    {
        $stream = $this->data->stream();

        if ($stream !== null) {
            app(StreamLedger::class)->leave($stream, $this->data->messageId);
        }
    }

    /**
     * The delivery ended without success, so it holds its stream until it is resent or skipped.
     */
    private function holdStream(): void
    {
        $stream = $this->data->stream();

        if ($stream !== null) {
            app(StreamLedger::class)->hold($stream, $this->data->messageId);
        }
    }

    /**
     * Whether releasing this job actually re-queues it.
     *
     * Only the sync connection is asked about, and a null job deliberately is not: a job
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
     * Without this, a 429 asking for longer than the cap re-runs the delivery immediately, once
     * per remaining deferral. On the sync connection with the shipped defaults and a
     * `Retry-After: 3600`, that is seven requests within milliseconds at the endpoint that has
     * just asked for an hour of quiet — the initial attempt plus all six deferrals.
     *
     * The code two branches up already warns about exactly this shape for a zero cap and
     * excludes it. The same thing happens on `sync` at any cap, because there the delay is not
     * short — it is not honored at all.
     *
     * An SQS queue whose name ends in `.fifo` cannot hold it back either. A FIFO queue takes a
     * delay only for the whole queue, never per message, so `SqsQueue` leaves `DelaySeconds` off
     * the send rather than have AWS refuse it, and the deferred job waits for the queue's own
     * delay at most, not for the cap. AWS requires that suffix on every FIFO queue name, and it is
     * what Laravel reads before it drops the delay. A release still waits on such a queue,
     * because it changes the visibility of the message instead.
     */
    private function queueCanDelay(): bool
    {
        return self::connectionCanHoldBack($this->connection, $this->queue);
    }

    /**
     * Whether a job dispatched with a delay onto this queue connection and queue really waits
     * that delay: not on the sync connection, and not on an SQS queue whose name ends in
     * `.fifo`, for the reasons {@see self::queueCanDelay()} gives. An ordered delivery waits for
     * its turn exactly this way, so it is refused on a connection where this is false.
     *
     * @internal
     */
    public static function connectionCanHoldBack(?string $connection, ?string $queue): bool
    {
        $resolved = app(QueueManager::class)->connection($connection);

        if ($resolved instanceof SyncQueue) {
            return false;
        }

        return ! $resolved instanceof SqsQueue || ! str_ends_with($resolved->getQueue($queue), '.fifo');
    }

    /**
     * How long a deferral holds the job back: the Retry-After cap, and on SQS no longer than its
     * message timer accepts. A cap above that would make the send throw instead of waiting; held
     * at the timer's limit, the job comes back sooner and defers again while the endpoint still
     * asks for more, each time counted against `retryAfterMaxDeferrals`.
     */
    private function deferralDelay(): int
    {
        $cap = $this->data->options->retryAfterCap;

        return app(QueueManager::class)->connection($this->connection) instanceof SqsQueue
            ? min($cap, self::SQS_MAX_DELAY_SECONDS)
            : $cap;
    }

    /**
     * Continue the delivery in a fresh job after the Retry-After cap, carrying the
     * attempts made so far and one more deferral. A release() cannot be used: it
     * re-pushes the original payload, so the counters would be lost.
     */
    private function defer(int $attempt, int $requested): void
    {
        $delay = $this->deferralDelay();

        event(new WebhookAttemptDeferred($this->data, $attempt, $delay, $requested));

        $job = new self($this->data->deferred($attempt));
        $job->onQueue($this->queue);
        $job->onConnection($this->connection);
        $job->delay($delay);

        dispatch($job);
    }

    private function finalFailure(int $attempt, ?TransportResponse $response, Throwable $exception): void
    {
        $this->holdStream();

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
