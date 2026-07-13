<?php

declare(strict_types=1);

namespace Webhooks\Core\Signing\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a {@see WebhookMessage} is constructed with
 * an id or timestamp that violates the Standard Webhooks rules (a "." in the id,
 * an empty id, or a non-positive timestamp). This is a programmer error on the
 * SENDING side — never confused with untrusted receive-side input, which is
 * reported as a {@see VerificationResult} instead.
 */
final class InvalidMessage extends InvalidArgumentException
{
    public static function id(string $id): self
    {
        return new self("A webhook message id must be non-empty and must not contain a '.', got: [{$id}].");
    }

    public static function timestamp(int $timestamp): self
    {
        return new self("A webhook message timestamp must be a positive Unix time, got: [{$timestamp}].");
    }
}
