<?php

declare(strict_types=1);

namespace Webhooks\Server\Backoff;

/**
 * Computes how long to wait before the retry that follows a failed attempt. The
 * default is {@see ExponentialWithJitter}. The optional Retry-After hint is a
 * seam: a strategy MAY honour a server-supplied delay (e.g. from a 429/503
 * `Retry-After` header) instead of its own schedule — wired up later, but
 * present from day zero so it stays an additive change.
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
