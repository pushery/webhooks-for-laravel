<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

/**
 * One station of a {@see FetchedChain}: the URL that was asked, the status it answered with, the
 * `Location` it named, and how long the transfer took.
 */
final readonly class RedirectHop
{
    public function __construct(
        public string $url,
        public int $status,
        public ?string $location,
        public int $durationMs,
    ) {}
}
