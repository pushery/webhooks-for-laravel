<?php

declare(strict_types=1);

namespace Webhooks\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Webhooks\Models\WebhookDelivery;
use Webhooks\Support\Settings;

/**
 * Pushes an engine-written row into Scout's search index.
 *
 * The delivery log is written through the base {@see WebhookDelivery} model and the inbound call
 * log through a raw SQL upsert — neither fires Scout's ModelObserver, which is registered on the
 * searchable SUBCLASS ({@see SearchableWebhookDelivery} / {@see SearchableWebhookCall}). So an
 * external engine (Meilisearch, Algolia, …) would never receive a row and search would silently
 * return nothing. This indexes the row explicitly after each write.
 *
 * It is a no-op unless BOTH hold: search is enabled AND the configured model is a searchable one
 * (an {@see Indexed}). The default base models are not Indexed, and the collection/database Scout
 * engines read the table directly and need no push, so a host that has not opted into search — or
 * one on a DB-backed engine — pays nothing.
 *
 * @internal
 */
final class SearchIndexer
{
    /**
     * Index a delivery-log row, resolved through the configured dashboard source model — the
     * model a host points at the searchable subclass to enable delivery search.
     */
    public static function indexDelivery(int|string $id): void
    {
        if (! new Settings()->searchEnabled()) {
            return;
        }

        $model = Config::get('webhooks.dashboard.source_model', WebhookDelivery::class);

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            return;
        }

        $row = $model::query()->whereKey($id)->first();

        if ($row instanceof Indexed) {
            $row->searchable();
        }
    }

    /**
     * Index an already-loaded row (the inbound-call log reads its row back through the configured
     * model right after the raw insert, so there is nothing to re-query).
     */
    public static function indexModel(?Model $model): void
    {
        if ($model instanceof Indexed && new Settings()->searchEnabled()) {
            $model->searchable();
        }
    }
}
