<?php

declare(strict_types=1);

namespace Webhooks\Search;

/**
 * A model that pushes itself into Laravel Scout's index. The shipped searchable log models
 * ({@see SearchableWebhookDelivery}, {@see SearchableWebhookCall}) implement it through Scout's
 * `Searchable` trait; the plain base models do not. {@see SearchIndexer} uses it to index a row
 * that was written through a base model or a raw SQL upsert — writes that never fire Scout's
 * per-subclass observer — so an external engine actually receives the row.
 *
 * @internal
 */
interface Indexed
{
    /**
     * Make this model searchable — mirrors Scout's `Searchable::searchable()`. No native return
     * type: it must stay compatible with the trait method that satisfies it.
     *
     * @return void
     */
    public function searchable();
}
