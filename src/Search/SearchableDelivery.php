<?php

declare(strict_types=1);

namespace Webhooks\Search;

use Illuminate\Support\Str;
use Laravel\Scout\Builder;
use Laravel\Scout\Searchable;
use Webhooks\Support\Settings;

/**
 * Makes an outbound delivery-log model searchable through Laravel Scout, indexing
 * only queryable, non-sensitive fields. Apply it to a WebhookDelivery model — the
 * shipped {@see SearchableWebhookDelivery} already does — after installing
 * laravel/scout (a Composer suggestion) and pointing webhooks.dashboard.source_model
 * at the searchable model. Indexing stays gated by webhooks.search.enabled, so
 * nothing is written to an index until search is switched on.
 */
trait SearchableDelivery
{
    use Searchable;

    /**
     * The queryable projection of a delivery for the search index. Only
     * non-sensitive, filterable fields are indexed: the event type, the endpoint
     * URL, the status, the owner/tenant morph pair (owner_type + owner_id), the
     * timestamp, and a short payload excerpt — never the full logged payload. The
     * WHOLE morph pair is indexed so a tenant-scoped search can filter both columns:
     * owner_id alone conflates two tenants that share an id under different owner
     * types. A payload that was offloaded to a Storage disk is not read back or
     * indexed; its excerpt is empty, so a large body is never copied into the index.
     *
     * @return array<string, scalar|null>
     */
    public function toSearchableArray(): array
    {
        return [
            'event_type' => $this->event_type,
            'url' => $this->subscription->url,
            'status' => $this->status->value,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'created_at' => $this->created_at->toIso8601String(),
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
     * A tenant-scoped search: every query is constrained to the WHOLE owner morph pair
     * (owner_type + owner_id), so one tenant can never see another tenant's deliveries
     * even when the underlying index is shared — cross-tenant isolation holds ONLY
     * because both columns are matched; the owner_id alone conflates two tenants that
     * share an id under different owner types. Pass an empty query to list an owner's
     * rows, or a term to full-text search within them.
     *
     * @return Builder<static>
     */
    public static function searchForOwner(string $ownerType, int|string $ownerId, string $query = ''): Builder
    {
        return static::search($query)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId);
    }

    /**
     * A short, index-safe excerpt of the payload. An offloaded payload is never read
     * back or indexed verbatim — its excerpt is empty; an inline payload is
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
