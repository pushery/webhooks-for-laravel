<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Feed;

use JsonSerializable;
use Override;
use Pushery\Webhooks\WebhookManager;

/**
 * One page of an endpoint's event feed, as {@see WebhookManager::feed()} returns it.
 *
 * Each event has the shape of the envelope the endpoint is sent: `id`, `type`, `created_at` and
 * `data`. Pass `$nextCursor` as the `after` of the next call to read on. With nothing left to read,
 * `$hasMore` is false and the cursor stays where the reader stands, so the next call picks up
 * whatever was sent since.
 */
final readonly class EventFeedPage implements JsonSerializable
{
    /**
     * @param  list<array{id: string, type: string, created_at: string, data: array<array-key, mixed>}>  $events
     */
    public function __construct(
        public array $events,
        /**
         * Where to read on: the last event of this page, or the cursor the page was asked from
         * when it is empty. Null only for a feed that has no event yet, read from its start.
         */
        public ?string $nextCursor,
        /** Whether more events are waiting beyond this page. */
        public bool $hasMore,
    ) {}

    /**
     * The events under `data`, and where to read on under `meta`.
     *
     * @return array{data: list<array{id: string, type: string, created_at: string, data: array<array-key, mixed>}>, meta: array{next_cursor: string|null, has_more: bool}}
     */
    public function toArray(): array
    {
        return [
            'data' => $this->events,
            'meta' => [
                'next_cursor' => $this->nextCursor,
                'has_more' => $this->hasMore,
            ],
        ];
    }

    /**
     * @return array{data: list<array{id: string, type: string, created_at: string, data: array<array-key, mixed>}>, meta: array{next_cursor: string|null, has_more: bool}}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
