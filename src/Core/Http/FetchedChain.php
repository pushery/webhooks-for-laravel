<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

/**
 * Where {@see GuardedFetch::follow()} went: every station in order, the response of the last one,
 * and, where the chain ended at a redirect, why, and the target it did not go to.
 */
final readonly class FetchedChain
{
    /**
     * @param  non-empty-list<RedirectHop>  $hops  every request, the first URL first
     * @param  FetchedResponse  $response  the response of the last station
     * @param  ?RedirectStop  $stoppedBecause  null where the last station is no redirect, or one the chain may not follow
     * @param  ?string  $notFollowed  the target of the redirect the chain ended at
     */
    public function __construct(
        public array $hops,
        public FetchedResponse $response,
        public ?RedirectStop $stoppedBecause = null,
        public ?string $notFollowed = null,
    ) {}

    /** The URL of the last station. */
    public function finalUrl(): string
    {
        return $this->hops[array_key_last($this->hops)]->url;
    }

    /** How many redirects the chain followed. */
    public function redirects(): int
    {
        return count($this->hops) - 1;
    }
}
