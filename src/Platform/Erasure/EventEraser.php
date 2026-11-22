<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Erasure;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Pushery\Webhooks\Search\SearchIndexer;
use Pushery\Webhooks\Server\Models\WebhookOutboxEntry;
use Pushery\Webhooks\Support\TableLookup;
use Pushery\Webhooks\Support\Timestamp;
use Pushery\Webhooks\Support\WebhookConnection;
use RuntimeException;

/**
 * Deletes every stored copy of the events a caller names: their rows in the delivery log, the
 * search index entries of those rows, their entries in the transactional outbox, and the offloaded
 * payload objects nothing else points at.
 *
 * The rows are read and deleted through the TABLE, not through `webhooks.models`: a host's model
 * may carry a global scope, and a deletion that went through it would leave the rows the scope
 * hides, while reporting itself done.
 *
 * Offloaded objects are content-addressed, so one object holds the body of every row that carried
 * the same bytes, possibly of another event or of an inbound call. An object is deleted only when
 * no remaining row in either log points at it, the rule `webhooks:prune-orphaned-payloads` follows,
 * and only once the deletion has committed: inside a transaction that rolls back, the rows come
 * back and their bodies have to be there.
 *
 * @internal
 */
final class EventEraser
{
    private const string UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /**
     * How far before the moment in a time-ordered event id a row of that event may carry its
     * created_at: the id is minted before the row is written, by the same clock.
     */
    public const int CLOCK_MARGIN_SECONDS = 60;

    /**
     * @param  array<array-key, mixed>  $eventIds
     */
    public function erase(array $eventIds): ForgottenEvents
    {
        $ids = $this->validated($eventIds);

        if ($ids === []) {
            return new ForgottenEvents(0, 0, 0, 0);
        }

        $rowIds = [];
        $objects = [];

        foreach ($this->rows($ids)->get(['id', 'payload_disk', 'payload_path']) as $row) {
            if (is_string($row->id)) {
                $rowIds[] = $row->id;
            }

            if (is_string($row->payload_disk) && is_string($row->payload_path)) {
                $objects[$row->payload_disk."\0".$row->payload_path] = [$row->payload_disk, $row->payload_path];
            }
        }

        SearchIndexer::unindexDeliveries($rowIds);

        $deleted = $this->rows($ids)->delete();
        $outboxEntries = $this->deleteOutboxEntries($ids);

        $orphans = array_values(array_filter($objects, fn (array $object): bool => ! $this->stillReferenced(...$object)));

        // A disk that does not throw answers a refused delete with false, Laravel's own disks among them, and the
        // payload would stay while the erasure counts it as gone. Every object is tried, and the ones that stayed are
        // named: their rows are gone, so webhooks:prune-orphaned-payloads finds them as orphans once the disk lets it.
        $deleteObjects = static function () use ($orphans): void {
            $kept = [];

            foreach ($orphans as [$disk, $path]) {
                $filesystem = Storage::disk($disk);

                if (! $filesystem->delete($path) && $filesystem->exists($path)) {
                    $kept[] = $disk.':'.$path;
                }
            }

            if ($kept !== []) {
                throw new RuntimeException('The payload of an erased event could not be deleted: '.implode(', ', $kept).'.');
            }
        };

        $connection = WebhookConnection::db();

        // Outside a transaction this runs at once; inside one, when it commits.
        $connection instanceof Connection ? $connection->afterCommit($deleteObjects) : $deleteObjects();

        return new ForgottenEvents($deleted, count($orphans), count($objects) - count($orphans), $outboxEntries);
    }

    /**
     * Deletes the entries of the transactional outbox these events were recorded as, in every state,
     * and returns how many there were.
     *
     * An entry holds the delivery sealed whole, under the event id as its message id. One still
     * waiting would not be sent, because the delivery gate refuses a delivery whose log row is gone,
     * but it would end refused and keep its sealed copy until pruning. Deleted, it holds no stream
     * back either: the relay waits only for earlier entries that are still there.
     *
     * Deleted through the table of the configured model, the one the relay sends from, and not
     * through the model, so that a global scope there cannot hide an entry from the erasure. Where
     * the outbox was never migrated there is no table and nothing to delete.
     *
     * @param  non-empty-list<string>  $ids
     */
    private function deleteOutboxEntries(array $ids): int
    {
        $model = WebhookOutboxEntry::model();
        $table = new $model()->getTable();

        if (! TableLookup::exists($table, WebhookConnection::name())) {
            return 0;
        }

        return WebhookConnection::db()->table($table)->whereIn('message_id', $ids)->delete();
    }

    /**
     * The delivery-log rows of these events. Bounded below by the oldest moment the ids carry when
     * every one of them is time-ordered, so on PostgreSQL only the partitions from that month on
     * are read; a redelivery is written later than its event and stays inside the bound.
     *
     * @param  non-empty-list<string>  $ids
     */
    private function rows(array $ids): Builder
    {
        $query = WebhookConnection::db()->table('webhook_deliveries')->whereIn('event_id', $ids);
        $since = $this->earliestMoment($ids);

        if ($since instanceof DateTimeImmutable) {
            $query->where('created_at', '>=', Timestamp::forDialect(WebhookConnection::dialect(), $since));
        }

        return $query;
    }

    /**
     * The moment the oldest id was minted, or null when one of them carries no moment.
     *
     * @param  non-empty-list<string>  $ids
     */
    private function earliestMoment(array $ids): ?DateTimeImmutable
    {
        // Version 7 keeps the milliseconds since the epoch in its first 48 bits; an id of any other
        // version carries no moment, and one of them leaves the whole read unbounded.
        if (array_any($ids, static fn (string $id): bool => $id[14] !== '7')) {
            return null;
        }

        $earliest = min(array_map(static fn (string $id): int => (int) hexdec(substr(str_replace('-', '', $id), 0, 12)), $ids));
        $seconds = intdiv($earliest, 1000) - self::CLOCK_MARGIN_SECONDS;

        return new DateTimeImmutable('@'.$seconds);
    }

    /**
     * Whether a row that stays points at this object, in the delivery log or the inbound call log.
     */
    private function stillReferenced(string $disk, string $path): bool
    {
        return array_any(
            ['webhook_deliveries', 'webhook_calls'],
            static fn (string $table): bool => TableLookup::exists($table, WebhookConnection::name()) && WebhookConnection::db()->table($table)
                ->where('payload_disk', $disk)
                ->where('payload_path', $path)
                ->exists(),
        );
    }

    /**
     * The ids as given, each an event id the package minted. Anything else is refused: the column
     * is a UUID, and a value that cannot be one matches nothing, so it is a caller's mistake that
     * would otherwise read as "nothing stored".
     *
     * @param  array<array-key, mixed>  $eventIds
     * @return list<string>
     */
    private function validated(array $eventIds): array
    {
        $ids = [];

        foreach ($eventIds as $id) {
            if (! is_string($id) || preg_match(self::UUID, $id) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'An event id to forget must be the UUID a dispatch returned in event_id, got %s.',
                    is_string($id) ? "'".$id."'" : get_debug_type($id),
                ));
            }

            $ids[] = strtolower($id);
        }

        return array_values(array_unique($ids));
    }
}
