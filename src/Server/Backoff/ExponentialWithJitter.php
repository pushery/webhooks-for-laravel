<?php

declare(strict_types=1);

namespace Webhooks\Server\Backoff;

/**
 * Exponential backoff with FULL jitter: the delay before retry N is a uniform
 * random value in `[0, min(cap, base * 2^(N-1))]`. Full jitter is the
 * thundering-herd-safe choice — many endpoints failing at once retry at spread-out
 * times instead of hammering in lockstep. The cap (default 900s) preserves the SQS
 * visibility-timeout ceiling; raise it when not on SQS.
 *
 * When a Retry-After hint is supplied it wins: a server that asked us to wait a
 * specific time is obeyed rather than jittered. It is clamped by its OWN cap, not by
 * the jitter cap — the jitter cap exists to stay under a queue's visibility timeout,
 * while an endpoint's rate-limit window is routinely longer than that, and silently
 * shortening it means coming back while it is still refusing us. What happens when a
 * hint exceeds that cap is the job's decision, not the schedule's
 * ({@see CallWebhookJob}): it waits the cap without charging
 * the attempt to the retry budget.
 */
final readonly class ExponentialWithJitter implements BackoffStrategy
{
    /** Bounds the shift so `base << shift` can never overflow to a negative int. */
    private const int MAX_SHIFT = 30;

    private int $baseSeconds;

    private int $retryAfterCapSeconds;

    public function __construct(
        int $baseSeconds = 10,
        private int $capSeconds = 900,
        ?int $retryAfterCapSeconds = null,
    ) {
        // Floor the base at one second so a misconfigured base of 0 (or negative)
        // can never collapse the schedule to a zero-delay retry storm.
        $this->baseSeconds = max(1, $baseSeconds);
        $this->retryAfterCapSeconds = max(0, $retryAfterCapSeconds ?? $capSeconds);
    }

    public function delayAfterAttempt(int $attempt, ?int $retryAfterSeconds = null): int
    {
        if ($retryAfterSeconds !== null) {
            return max(0, min($this->retryAfterCapSeconds, $retryAfterSeconds));
        }

        $ceiling = min($this->capSeconds, $this->baseSeconds << min(max(0, $attempt - 1), self::MAX_SHIFT));

        return random_int(0, max(0, $ceiling));
    }

    public function schedule(int $maxTries): array
    {
        $retries = max(0, $maxTries - 1);

        if ($retries === 0) {
            return [];
        }

        return array_map(fn (int $attempt): int => $this->delayAfterAttempt($attempt), range(1, $retries));
    }
}
