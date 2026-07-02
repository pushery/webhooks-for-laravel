<?php

declare(strict_types=1);

namespace Webhooks\Exceptions;

use RuntimeException;

/**
 * Thrown when an event payload does not satisfy the JSON Schema declared for its
 * type in the event catalog, and payload validation is enabled. It is raised
 * before any delivery is created, so a malformed event never reaches a subscriber.
 * The formatted schema violations are available via {@see self::$errors}.
 */
final class InvalidPayloadException extends RuntimeException
{
    /**
     * @param  array<array-key, mixed>  $errors  Keyword-path => message map from the validator.
     */
    private function __construct(string $message, public readonly array $errors)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<array-key, mixed>  $errors
     */
    public static function forEvent(string $eventType, array $errors): self
    {
        return new self(
            sprintf('The payload for webhook event "%s" does not satisfy its catalog schema.', $eventType),
            $errors,
        );
    }
}
