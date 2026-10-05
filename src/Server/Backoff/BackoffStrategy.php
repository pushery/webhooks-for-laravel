<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Backoff;

/**
 * Computes how long to wait before the retry that follows a failed attempt. The
 * default is {@see ExponentialWithJitter}. The job passes the endpoint's Retry-After
 * hint when a retryable 429/503 carried a readable one and the delivery honors
 * Retry-After (`respectRetryAfter`); a strategy MAY wait that long instead of following
 * its own schedule. Null means there is no hint to honor.
 */
interface BackoffStrategy
{
    /**
     * @param  int  $attempt  the 1-based number of the attempt that just failed
     * @param  int|null  $retryAfterSeconds  a server-requested delay, if any
     * @return int seconds to wait before the next attempt (>= 0)
     */
    public function delayAfterAttempt(int $attempt, ?int $retryAfterSeconds = null): int;

    /**
     * The full delay schedule for a job with the given maximum number of tries —
     * one entry per retry (so `maxTries - 1` entries), consumed by the queue job's
     * `backoff()`.
     *
     * @return list<int>
     */
    public function schedule(int $maxTries): array;
}
