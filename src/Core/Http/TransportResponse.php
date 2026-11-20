<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

/**
 * The captured outcome of a delivery attempt: the HTTP status, the response
 * headers, the response body (capped to the configured byte limit, with a
 * truncation flag), and the wall-clock duration in milliseconds. The Server layer
 * classifies this into success/retry/final-failure and records duration_ms.
 */
final readonly class TransportResponse
{
    /**
     * @param  array<array-key, list<string>>  $headers  each header with its values; a name made of digits is an int
     *                                                   key, the way PHP stores it
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public bool $truncated,
        public int $durationMs,
    ) {}
}
