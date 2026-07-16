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

    private int $capSeconds;

    private int $retryAfterCapSeconds;

    public function __construct(
        int $baseSeconds = 10,
        int $capSeconds = 900,
        ?int $retryAfterCapSeconds = null,
    ) {
        // Floor the base AND the cap at one second so a misconfigured 0 (or negative) can never
        // collapse the schedule to a zero-delay retry storm: the delay is random_int(0, ceiling)
        // and ceiling is min(cap, base << shift), so a zero cap would floor every draw to 0.
        $this->baseSeconds = max(1, $baseSeconds);
        $this->capSeconds = max(1, $capSeconds);
        $this->retryAfterCapSeconds = max(0, $retryAfterCapSeconds ?? $capSeconds);
    }

    /**
     * A copy whose Retry-After clamp is the given seconds, leaving the jitter cap (the
     * queue-visibility ceiling) untouched. The delivery builder uses this so raising the
     * Retry-After cap on a single call also raises the clamp its released delay is bound
     * by — otherwise the defer threshold and the delay clamp, which are the SAME wait,
     * would silently disagree and the call would come back at the old cap while the
     * endpoint is still rate-limiting it.
     */
    public function withRetryAfterCap(int $retryAfterCapSeconds): self
    {
        return new self($this->baseSeconds, $this->capSeconds, max(0, $retryAfterCapSeconds));
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
