<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Data;

use Illuminate\Support\Facades\Date;
use Pushery\Webhooks\Core\Signing\SignatureScheme;
use Pushery\Webhooks\Server\Backoff\BackoffStrategy;
use Pushery\Webhooks\Server\Jobs\CallWebhookJob;

/**
 * The immutable, queue-serializable context for one webhook delivery. Carries
 * everything the {@see CallWebhookJob} needs — but not the
 * raw signing secret: that travels as {@see self::$encryptedSecret}, sealed via the
 * app encrypter and unsealed at handle time, so a signing secret is never at rest in
 * cleartext in the queue store.
 *
 * The {@see self::$messageId} is stable across attempts (the Standard Webhooks
 * `webhook-id`), so a retry re-signs with the same id and the receiver dedupes.
 *
 * {@see self::$attemptOffset} and {@see self::$retryAfterDeferrals} carry the parts of
 * a delivery's history that a queue release cannot: releasing a job re-pushes its
 * original payload, so state mutated on the job object is lost. A delivery that honors
 * an endpoint's long Retry-After is therefore re-dispatched as a fresh job carrying
 * these two counters — how many requests it has already made, and how many of those
 * ended in a rate-limit wait that must not be charged to its retry budget.
 *
 * {@see self::$orderingKey} puts the delivery into a stream with every other delivery to
 * the same URL under the same key, {@see self::$retryForSeconds} replaces the number of
 * tries with a time to keep trying, and {@see self::$retryUntil} is the moment that time
 * ends. {@see self::$retryableProblemTypes} names the problem types a 4xx answer is retried
 * for. A job queued by an earlier version carries none of these four, nor
 * {@see self::$streamWaits}: a readonly property the serialized job never had stays
 * uninitialized when it is unserialized, so they are read through the methods below, which
 * answer the defaults for such a job instead of throwing in the worker.
 */
final readonly class WebhookDeliveryData
{
    /**
     * @param  class-string<SignatureScheme>  $schemeClass
     * @param  array<string, scalar|null>  $meta
     * @param  list<string>  $tags
     * @param  array<string, string>  $headers
     * @param  ?string  $orderingKey  the key of the stream the delivery goes out in, or null for none
     * @param  ?int  $retryForSeconds  how long the delivery keeps trying, or null to count tries instead
     * @param  ?int  $retryUntil  the Unix time that keeping-trying ends at, or null
     * @param  ?int  $streamWaits  how often the delivery has waited for an earlier one of its stream, null for never
     * @param  ?list<string>  $retryableProblemTypes  the problem types a 4xx answer is retried for, null for none
     */
    public function __construct(
        public string $messageId,
        public string $url,
        public string $rawBody,
        public string $schemeClass,
        public BackoffStrategy $backoff,
        public DeliveryOptions $options,
        public int $maxTries = 3,
        public ?string $eventType = null,
        public ?string $encryptedSecret = null,
        public bool $doNotSign = false,
        public array $meta = [],
        public array $tags = [],
        public array $headers = [],
        public int $attemptOffset = 0,
        public int $retryAfterDeferrals = 0,
        public ?string $orderingKey = null,
        public ?int $retryForSeconds = null,
        public ?int $retryUntil = null,
        public ?int $streamWaits = null,
        public ?array $retryableProblemTypes = null,
    ) {}

    /**
     * The key of the stream this delivery goes out in, or null when it goes out on its own.
     */
    public function orderingKey(): ?string
    {
        return $this->orderingKey ?? null;
    }

    /**
     * The stream this delivery belongs to: its URL and its ordering key, hashed into one value
     * of fixed width, or null when it has no ordering key. Two deliveries share a stream only
     * when both the URL and the key are the same.
     */
    public function stream(): ?string
    {
        $key = $this->orderingKey();

        return $key === null ? null : hash('sha256', $this->url."\n".$key);
    }

    /**
     * How long this delivery keeps trying once it is due, in seconds, or null when its number
     * of tries decides instead.
     */
    public function retryForSeconds(): ?int
    {
        return $this->retryForSeconds ?? null;
    }

    /**
     * The Unix time this delivery stops trying at, or null when its number of tries decides.
     */
    public function retryUntil(): ?int
    {
        return $this->retryUntil ?? null;
    }

    /**
     * How often this delivery has waited for an earlier delivery of its stream.
     */
    public function streamWaits(): int
    {
        return $this->streamWaits ?? 0;
    }

    /**
     * The problem types a 4xx answer of this delivery is retried for, beside the statuses the
     * configuration retries: the `type` of an `application/problem+json` body.
     *
     * @return list<string>
     */
    public function retryableProblemTypes(): array
    {
        return $this->retryableProblemTypes ?? [];
    }

    /**
     * The same delivery, continued in a fresh job after a Retry-After wait longer than
     * the queue can hold: the requests made so far are remembered, and this wait is
     * recorded as a deferral so it is not charged against the retry budget.
     */
    public function deferred(int $attemptsMade): self
    {
        return $this->continued($attemptsMade, $this->retryAfterDeferrals + 1, $this->streamWaits(), $this->retryUntil());
    }

    /**
     * The same delivery, back in the queue after waiting $delay seconds for an earlier delivery
     * of its stream. It has made no request yet, so its time to keep trying starts once the wait
     * is over rather than when it was dispatched.
     */
    public function waited(int $delay): self
    {
        $seconds = $this->retryForSeconds();

        return $this->continued(
            $this->attemptOffset,
            $this->retryAfterDeferrals,
            $this->streamWaits() + 1,
            $seconds === null ? null : Date::now()->getTimestamp() + $delay + $seconds,
        );
    }

    /**
     * The same delivery, sent again from the start: no request made, no deferral and no wait
     * counted, and the whole time to keep trying ahead of it again.
     */
    public function restarted(): self
    {
        $seconds = $this->retryForSeconds();

        return $this->continued(0, 0, 0, $seconds === null ? null : Date::now()->getTimestamp() + $seconds);
    }

    private function continued(int $attemptOffset, int $retryAfterDeferrals, int $streamWaits, ?int $retryUntil): self
    {
        return new self(
            messageId: $this->messageId,
            url: $this->url,
            rawBody: $this->rawBody,
            schemeClass: $this->schemeClass,
            backoff: $this->backoff,
            options: $this->options,
            maxTries: $this->maxTries,
            eventType: $this->eventType,
            encryptedSecret: $this->encryptedSecret,
            doNotSign: $this->doNotSign,
            meta: $this->meta,
            tags: $this->tags,
            headers: $this->headers,
            attemptOffset: $attemptOffset,
            retryAfterDeferrals: $retryAfterDeferrals,
            orderingKey: $this->orderingKey(),
            retryForSeconds: $this->retryForSeconds(),
            retryUntil: $retryUntil,
            streamWaits: $streamWaits,
            retryableProblemTypes: $this->retryableProblemTypes(),
        );
    }
}
