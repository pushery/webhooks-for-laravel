<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Feed;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Exceptions\FeedCursorExpired;
use Pushery\Webhooks\Models\WebhookDelivery;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Support\WebhookConnection;
use Pushery\Webhooks\WebhookManager;

/**
 * Reads one endpoint's event feed out of the delivery log, for {@see WebhookManager::feed()}.
 *
 * The log already holds every event an endpoint was sent, with the id and the payload of the
 * delivery, so the feed is a read of it and needs no store of its own. Five rules shape the read:
 *
 *  - An event appears once, at its first delivery. A redelivery writes another row under the same
 *    event id, and a receiver that already processed the event would otherwise read it twice.
 *  - A test ping is left out. It is a request somebody sent to check the endpoint, and a receiver
 *    catching up has nothing to process in it.
 *  - A delivery becomes readable {@see self::SETTLE_SECONDS} after it was logged. A dispatch inside
 *    a database transaction commits its row later than the row's creation time says, and a reader
 *    that had already passed that time would never see it. The wait outlasts such a transaction,
 *    and the feed is for catching up, so a minute's delay costs it nothing.
 *  - The walk runs in the order of (created_at, id). Both are fixed when the row is written, so a
 *    cursor stays valid however many rows arrive after it.
 *  - The feed reaches back as far as the retention window of the delivery log. A cursor from
 *    before it is refused, because the rows that followed it may have been removed.
 *
 * @internal
 */
final readonly class EventFeed
{
    /** The largest page a caller may ask for. */
    public const int MAX_LIMIT = 1000;

    /** Seconds a delivery waits after it was logged before the feed shows it. */
    public const int SETTLE_SECONDS = 60;

    /** The event type of {@see WebhookManager::ping()}. */
    private const string PING = 'webhooks.ping';

    public function __construct(private int $retentionMonths) {}

    /**
     * @param  Closure(array<array-key, mixed>): array<array-key, mixed>  $shape  reshapes an event's
     *                                                                            data for the endpoint,
     *                                                                            as a delivery does
     *
     * @throws InvalidArgumentException when the limit is outside 1 to {@see self::MAX_LIMIT}
     * @throws FeedCursorExpired when the cursor points before the delivery log's retention window
     */
    public function page(WebhookSubscription $subscription, ?string $after, int $limit, Closure $shape): EventFeedPage
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('A feed page holds 1 to %d events, %d were asked for.', self::MAX_LIMIT, $limit));
        }

        $cursor = $after === null ? null : FeedCursor::decode($after);

        if ($cursor instanceof FeedCursor && $cursor->instant()->lessThan($this->retainedSince())) {
            throw new FeedCursorExpired($this->retentionMonths);
        }

        $rows = $this->events($subscription, $cursor)->limit($limit + 1)->get();
        $page = $rows->take($limit);
        $last = $page->last();

        return new EventFeedPage(
            array_values($page->map(static fn (WebhookDelivery $delivery): array => [
                'id' => $delivery->event_id,
                'type' => $delivery->event_type,
                // Carbon answers null only for a year outside 0 to 9999, which no stored delivery has.
                'created_at' => $delivery->created_at->toISOString() ?? '',
                'data' => $shape($delivery->rehydratedPayload()),
            ])->all()),
            $last instanceof WebhookDelivery ? FeedCursor::at($last)->encode() : $after,
            $rows->count() > $limit,
        );
    }

    /**
     * The bound {@see WebhookDelivery::scopeWithinRetention()} reads from: the first day of the
     * oldest month the delivery log still keeps whole. Before it a partition may already be gone.
     */
    private function retainedSince(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfMonth()->subMonths($this->retentionMonths + 1);
    }

    /**
     * The endpoint's readable events after the cursor, in the order of the walk.
     *
     * @return Builder<WebhookDelivery>
     */
    private function events(WebhookSubscription $subscription, ?FeedCursor $cursor): Builder
    {
        $model = WebhookDelivery::resolve();
        $table = $model->getTable();

        $query = $model->newQuery()
            ->where('subscription_id', $subscription->getKey())
            ->where('event_type', '!=', self::PING)
            ->createdBefore(now()->subSeconds(self::SETTLE_SECONDS))
            // Within the retention window only, so every cursor a page hands out is one the next
            // call accepts, until the window moves past it.
            ->withinRetention()
            // The first delivery of each event: no row of the same endpoint and event comes before
            // it in the walk. Every table name reaches the statement through the grammar, so the
            // connection's prefix applies to both sides of the comparison.
            ->whereNotExists(static function (QueryBuilder $earlier) use ($table): void {
                $earlier->selectRaw('1')
                    ->from($table, 'earlier')
                    ->whereColumn('earlier.subscription_id', "{$table}.subscription_id")
                    ->whereColumn('earlier.event_id', "{$table}.event_id")
                    ->where(static fn (QueryBuilder $before): QueryBuilder => $before
                        ->whereColumn('earlier.created_at', '<', "{$table}.created_at")
                        ->orWhere(static fn (QueryBuilder $tie): QueryBuilder => $tie
                            ->whereColumn('earlier.created_at', "{$table}.created_at")
                            ->whereColumn('earlier.id', '<', "{$table}.id")));
            })
            ->orderBy('created_at')
            ->orderBy('id');

        if (! $cursor instanceof FeedCursor) {
            return $query;
        }

        // PostgreSQL range-scans (created_at, id) as one row value; MySQL gets the OR form, which
        // returns the same rows.
        if (WebhookConnection::dialect() !== Dialect::MySql) {
            return $query->whereRowValues(['created_at', 'id'], '>', [$cursor->createdAt, $cursor->deliveryId]);
        }

        return $query->where(static fn (Builder $seek): Builder => $seek
            ->where('created_at', '>', $cursor->createdAt)
            ->orWhere(static fn (Builder $tie): Builder => $tie
                ->where('created_at', $cursor->createdAt)
                ->where('id', '>', $cursor->deliveryId)));
    }
}
