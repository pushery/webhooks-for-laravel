<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

/**
 * What a {@see GuardedFetch} brought back: the status, the response headers, the body, never
 * longer than the limit the fetch was given, and how long the transfer took.
 */
final readonly class FetchedResponse
{
    /**
     * @param  array<string, list<string>>  $headers  each header with its values, under the name the server sent
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public int $durationMs,
    ) {}

    /** The first value of a header, its name matched in any case, or null when the response has none. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $values) {
            if (strcasecmp($header, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
