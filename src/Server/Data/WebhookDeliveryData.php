<?php

declare(strict_types=1);

namespace Webhooks\Server\Data;

use Webhooks\Core\Signing\SignatureScheme;
use Webhooks\Server\Backoff\BackoffStrategy;

/**
 * The immutable, queue-serializable context for one webhook delivery. Carries
 * everything the {@see CallWebhookJob} needs — but NOT the
 * raw signing secret: that travels as {@see self::$encryptedSecret} (sealed via
 * the app encrypter) or is resolved by reference from {@see self::$meta} at handle
 * time, so a signing secret is never at rest in cleartext in the queue store.
 *
 * The {@see self::$messageId} is STABLE across attempts (the Standard Webhooks
 * `webhook-id`), so a retry re-signs with the same id and the receiver dedupes.
 *
 * {@see self::$attemptOffset} and {@see self::$retryAfterDeferrals} carry the parts of
 * a delivery's history that a queue release cannot: releasing a job re-pushes its
 * ORIGINAL payload, so state mutated on the job object is lost. A delivery that honours
 * an endpoint's long Retry-After is therefore re-dispatched as a fresh job carrying
 * these two counters — how many requests it has already made, and how many of those
 * ended in a rate-limit wait that must not be charged to its retry budget.
 */
final readonly class WebhookDeliveryData
{
    /**
     * @param  class-string<SignatureScheme>  $schemeClass
     * @param  array<string, scalar|null>  $meta
     * @param  list<string>  $tags
     * @param  array<string, string>  $headers
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
    ) {}

    /**
     * The same delivery, continued in a fresh job after a Retry-After wait longer than
     * the queue can hold: the requests made so far are remembered, and this wait is
     * recorded as a deferral so it is not charged against the retry budget.
     */
    public function deferred(int $attemptsMade): self
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
            attemptOffset: $attemptsMade,
            retryAfterDeferrals: $this->retryAfterDeferrals + 1,
        );
    }
}
