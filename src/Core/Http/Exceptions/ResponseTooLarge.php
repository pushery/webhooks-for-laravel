<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A fetched response was longer than the limit the caller gave, and the transfer ended where it
 * passed that limit, or before its first byte where the server declared a longer length.
 *
 * Nothing of the body is handed back. A body cut off at the limit is a broken image or a half
 * document, never the response the caller asked for, so the fetch refuses it as a whole.
 */
final class ResponseTooLarge extends RuntimeException
{
    public function __construct(
        public readonly int $limit,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function over(int $limit, ?Throwable $previous = null): self
    {
        return new self($limit, "The response is longer than the limit of {$limit} bytes.", $previous);
    }
}
