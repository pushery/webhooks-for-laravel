<?php

declare(strict_types=1);

namespace Webhooks\Search;

use Illuminate\Support\Str;
use Laravel\Scout\Builder;
use Laravel\Scout\Searchable;
use Webhooks\Support\Settings;

/**
 * Makes an inbound call-log model searchable through Laravel Scout, indexing only
 * queryable, non-sensitive fields. Apply it to a WebhookCall model — the shipped
 * {@see SearchableWebhookCall} already does — after installing laravel/scout (a
 * Composer suggestion) and pointing the client config's 'model' at the searchable
 * model. Indexing stays gated by webhooks.search.enabled, so nothing is written to
 * an index until search is switched on.
 */
trait SearchableCall
{
    use Searchable;

    /**
     * The queryable projection of a stored call for the search index. Only
     * non-sensitive, filterable fields are indexed: the source, the event type, the
     * status, the timestamp, and a short payload excerpt — never the redacted
     * headers and never the full body. A body that was offloaded to a Storage disk
     * is not read back or indexed; its excerpt is empty, so a large body is never
     * copied into the index.
     *
     * @return array<string, scalar|null>
     */
    public function toSearchableArray(): array
    {
        return [
            'source' => $this->source,
            'event_type' => $this->event_type,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'payload_excerpt' => $this->searchablePayloadExcerpt(),
        ];
    }

    /**
     * Whether this row should be written to the search index. Gated by
     * webhooks.search.enabled, so with search off nothing is ever indexed.
     */
    public function shouldBeSearchable(): bool
    {
        return new Settings()->searchEnabled();
    }

    /**
     * A source-scoped search: every query is constrained to a single producer, so
     * one source's calls never leak into another's results even when the underlying
     * index is shared. Pass an empty query to list a source's rows, or a term to
     * full-text search within them.
     *
     * @return Builder<static>
     */
    public static function searchForSource(string $source, string $query = ''): Builder
    {
        return static::search($query)->where('source', $source);
    }

    /**
     * A short, index-safe excerpt of the stored payload. An offloaded body is never
     * read back or indexed verbatim — its excerpt is empty; an inline payload is
     * truncated to the configured character budget.
     */
    private function searchablePayloadExcerpt(): string
    {
        if ($this->payload_disk !== null) {
            return '';
        }

        return Str::limit(
            json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            new Settings()->searchPayloadExcerptChars(),
            '',
        );
    }
}
