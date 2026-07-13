<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

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

    public function isValid(): bool
    {
        return $this->status === VerificationStatus::Valid;
    }

    public function reason(): string
    {
        return $this->status->value;
    }
}
