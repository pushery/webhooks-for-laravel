<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

use InvalidArgumentException;

/**
 * The signing/verification secret material: a current secret plus, during a rotation
 * window, the previous one — so a delivery can be signed with both and a consumer that
 * still holds the old secret keeps verifying while it migrates.
 *
 * A SecretSet holds the RAW secret tokens (e.g. "whsec_…"); deriving the HMAC key
 * bytes from a token is scheme-specific and belongs to the {@see SignatureScheme}
 * (Standard Webhooks strips the "whsec_" prefix and base64-decodes; a Stripe-style
 * scheme uses the raw UTF-8 bytes). Iteration is current-first so a verifier can
 * report which key matched.
 */
final readonly class SecretSet
{
    public const string CURRENT = 'current';

    public const string PREVIOUS = 'previous';

    private function __construct(
        private string $current,
        private ?string $previous,
    ) {
        if (trim($current) === '') {
            throw new InvalidArgumentException('A SecretSet requires a non-empty current secret.');
        }

        if ($previous !== null && trim($previous) === '') {
            throw new InvalidArgumentException('A SecretSet previous secret, when present, must be non-empty.');
        }
    }

    public static function fromCurrent(string $current): self
    {
        return new self($current, null);
    }

    public static function rotating(string $current, string $previous): self
    {
        return new self($current, $previous);
    }

    public function withPrevious(string $previous): self
    {
        return new self($this->current, $previous);
    }

    /**
     * The current secret token. A single-signature dialect (Stripe/GitHub
     * style) signs an outgoing message with this one; a rotation window still emits
     * a single header, so the previous secret is a verify-only concern.
     */
    public function current(): string
    {
        return $this->current;
    }

    /**
     * The active secret tokens, keyed by their logical id, current first. A
     * verifier tries them in order and reports the matched key id.
     *
     * @return array<non-empty-string, string>
     */
    public function all(): array
    {
        $secrets = [self::CURRENT => $this->current];

        if ($this->previous !== null) {
            $secrets[self::PREVIOUS] = $this->previous;
        }

        return $secrets;
    }
}
