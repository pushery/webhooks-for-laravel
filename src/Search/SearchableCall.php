<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Search;

use Illuminate\Support\Str;
use Laravel\Scout\Builder;
use Laravel\Scout\Searchable;
use Pushery\Webhooks\Support\Settings;

/**
 * Makes an inbound call-log model searchable through Laravel Scout, indexing only
 * queryable fields, and the body only where a host opts in. Apply it to a WebhookCall model — the shipped
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
     * filterable fields are always indexed: the source, the event type, the
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
        // The body is only indexed where a host has said so. An index is shared across
        // every reader and retained outside the application, so the per-request
        // `view-webhook-payload` ability cannot govern it — see the note on
        // `search.index_payload` in the shipped config.
        if (! new Settings()->searchIndexPayload()) {
            return '';
        }

        if ($this->payload_disk !== null) {
            return '';
        }

        // The `?: ''` is EQUIVALENT and reported every run. json_encode only answers false on
        // INF/NAN or invalid UTF-8, neither of which survives a jsonb column, and no successful
        // encoding of an ARRAY is falsy — `[]` encodes to '[]'. So nothing reaches the fallback.
        //
        // It stays because Str::limit is typed for a string: the moment that assumption stops
        // holding, the choice is an empty excerpt or a TypeError while indexing.
        return Str::limit(
            json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            new Settings()->searchPayloadExcerptChars(),
            '',
        );
    }
}
