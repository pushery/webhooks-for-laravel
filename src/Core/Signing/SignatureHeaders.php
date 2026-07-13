<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing;

/**
 * A scheme-agnostic, case-insensitive carrier for the signature headers a scheme
 * emits (sending side) or reads (receiving side). Standard Webhooks uses
 * `webhook-id` / `webhook-timestamp` / `webhook-signature`; a Stripe-style scheme
 * uses a single `Webhook-Signature`. Header names are compared case-insensitively
 * per RFC 9110, so a receiver never has to guess the producer's casing.
 */
final readonly class SignatureHeaders
{
    /** @var array<string, string> original-cased name => value */
    private array $headers;

    /** @var array<string, string> lower-cased name => value, for lookup */
    private array $lookup;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(array $headers)
    {
        $lookup = [];

        foreach ($headers as $name => $value) {
            $lookup[strtolower($name)] = $value;
        }

        $this->headers = $headers;
        $this->lookup = $lookup;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function from(array $headers): self
    {
        return new self($headers);
    }

    public function get(string $name): ?string
    {
        return $this->lookup[strtolower($name)] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->lookup);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->headers;
    }
}
