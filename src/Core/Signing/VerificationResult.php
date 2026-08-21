<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Signing;

use Pushery\Webhooks\Client\Verification\InboundVerifier;

/**
 * The typed result of {@see SignatureScheme::verify()}: a status plus, on success,
 * the id of the secret that matched (so a rotation can be observed). Carries no
 * detail about WHY an invalid signature failed beyond the coarse status — the
 * receiver must not leak which part failed to an untrusted caller.
 */
final readonly class VerificationResult
{
    private function __construct(
        public VerificationStatus $status,
        public ?string $matchedKeyId = null,
    ) {}

    public static function valid(string $matchedKeyId): self
    {
        return new self(VerificationStatus::Valid, $matchedKeyId);
    }

    public static function invalid(): self
    {
        return new self(VerificationStatus::Invalid);
    }

    public static function expired(): self
    {
        return new self(VerificationStatus::Expired);
    }

    public static function malformed(): self
    {
        return new self(VerificationStatus::Malformed);
    }

    /**
     * Authenticity could not be established — the check itself did not complete.
     *
     * For an {@see InboundVerifier} whose callback timed out,
     * failed DNS, or came back 5xx. It is NOT a softer valid: {@see self::isValid()} stays
     * false, nothing is stored and nothing is dispatched. What it buys is that "the provider
     * said this delivery does not exist" and "the provider did not answer" stop being the
     * same value — the first is a forgery and a security signal, the second is an outage
     * over a delivery that was probably genuine, and a receiver that cannot separate them
     * either alerts on every outage or on nothing.
     *
     * A signature scheme should never return this. It is a pure function of the bytes; it
     * always has a verdict, and reporting one it did not reach would tell the sender to
     * retry a signature that can never start verifying.
     */
    public static function undetermined(): self
    {
        return new self(VerificationStatus::Undetermined);
    }

    public function isValid(): bool
    {
        return $this->status === VerificationStatus::Valid;
    }

    public function reason(): string
    {
        return $this->status->value;
    }
}
