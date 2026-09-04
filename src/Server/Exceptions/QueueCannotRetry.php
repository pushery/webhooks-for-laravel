<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Exceptions;

use Pushery\Webhooks\Server\Jobs\CallWebhookJob;
use RuntimeException;
use Throwable;

/**
 * The delivery failed in a way that would normally be retried, and the queue connection it
 * is running on cannot retry anything.
 *
 * On the `sync` connection there is no worker. `SyncJob::release()` delegates to a parent
 * whose entire body is `$this->released = true;`, and `SyncQueue::executeJob()` never reads
 * that flag — so the job runs exactly once. Every other shipped connection really does
 * re-queue: `DatabaseJob` and `RedisJob` both override `release()` to do it.
 *
 * Without this, a retryable failure on `sync` left the delivery with no terminal event at
 * all: `WebhookAttemptRetrying` fired, `WebhookAttemptsExhausted` never did, `failed()` was
 * never called, and the row sat at its non-final state for ever. That is the exact state
 * {@see CallWebhookJob} promises cannot happen — *"no path may
 * end without a terminal event"* — and it is the one failure observability itself cannot
 * see, because nothing is wrong with any of the numbers, there is simply a row nobody
 * finishes.
 *
 * It carries the original failure as its previous exception: the delivery failed for a
 * reason, and the queue is why that reason became final rather than temporary.
 */
final class QueueCannotRetry extends RuntimeException
{
    /**
     * The message leads with the cause, and that ordering is the point, not a
     * preference. This text is what lands in `webhook_deliveries.error` — the subscriber
     * stores `$exception?->getMessage()` verbatim — so a message that opened with the queue
     * would replace "certificate has expired" with a sentence about configuration, and the
     * operator would go and check their queue while the endpoint's TLS stayed broken.
     *
     * The delivery failed for a reason. The queue is only why that reason became final, and
     * an error line that carries one without the other sends someone to debug the wrong half.
     */
    public static function onSyncConnection(?Throwable $cause = null): self
    {
        $reason = $cause?->getMessage();
        $prefix = $reason === null || $reason === '' ? '' : rtrim($reason, ' .').'. ';

        return new self(
            $prefix
            .'It was not retried: the queue connection this delivery ran on cannot retry — the sync '
            .'connection has no worker, so a released job is never picked up again. Give the server '
            .'layer a real queue connection (database, redis, sqs) to get retries and backoff.',
            previous: $cause,
        );
    }
}
