<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Support\Settings;

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
     *
     * The class is asked before the row is read: a source model that is not searchable has
     * nothing to index, and reading the row only to drop it is the cost the docblock above
     * promises a host without delivery search does not pay. The read itself carries the row's
     * partition key, bound as the column's own literal the way the model's own saves bind it:
     * the log is partitioned by created_at and keyed by (id, created_at), and by id alone the
     * planner probes the index of every partition, on each write of the engine's hot path.
     */
    public static function indexDelivery(WebhookDelivery $delivery): void
    {
        if (! new Settings()->searchEnabled()) {
            return;
        }

        $model = Config::get('webhooks.dashboard.source_model', WebhookDelivery::model());

        if (! is_string($model) || ! is_a($model, Model::class, true) || ! is_a($model, Indexed::class, true)) {
            return;
        }

        $query = $model::query()->whereKey($delivery->getKey());
        $createdAt = $delivery->getRawOriginal('created_at');

        // On the base query: created_at is a column of the log's table, and the configured
        // source model need not declare it as a property.
        if (is_string($createdAt) && $createdAt !== '') {
            $query->getQuery()->where('created_at', '=', $createdAt);
        }

        $row = $query->first();

        if ($row instanceof Indexed) {
            $row->searchable();
        }
    }

    /**
     * Take delivery-log rows out of the index before they are deleted. A delete through the query
     * builder never reaches Scout's observer, so an external engine would go on returning the rows
     * from its index after the log had let them go.
     *
     * Read without global scopes, because a row a host's scope hides is still in the index.
     *
     * @param  list<string>  $ids
     */
    public static function unindexDeliveries(array $ids): void
    {
        if ($ids === [] || ! new Settings()->searchEnabled()) {
            return;
        }

        $model = Config::get('webhooks.dashboard.source_model', WebhookDelivery::model());

        if (! is_string($model) || ! is_a($model, Model::class, true) || ! is_a($model, Indexed::class, true)) {
            return;
        }

        foreach ($model::query()->withoutGlobalScopes()->whereKey($ids)->get() as $row) {
            $row->unsearchable();
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
