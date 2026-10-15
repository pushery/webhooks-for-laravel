<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Streams;

use Closure;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Pushery\Webhooks\Server\Exceptions\OrderedDeliveryUnavailable;
use Pushery\Webhooks\Server\Jobs\CallWebhookJob;
use Pushery\Webhooks\Support\Timestamp;
use Pushery\Webhooks\Support\WebhookConnection;
use Throwable;

/**
 * The deliveries of every stream that have not succeeded yet, in the order they joined it.
 *
 * A row is written when an ordered delivery is dispatched and deleted when it succeeds or is
 * skipped, so the table holds what is in flight and what holds a stream, never history. The
 * oldest row of a stream is the one whose turn it is.
 *
 * A row's position is the stream's highest position plus one, and a unique index on the
 * stream and the position decides between two dispatches that read the same highest value:
 * the second insert waits for the first to commit, fails, and reads again. So a position is
 * only ever written once every lower one of its stream is committed, and a job that asks
 * whose turn it is cannot miss an older delivery that was still being written.
 *
 * @internal
 */
final readonly class StreamLedger
{
    public const string TABLE = 'webhook_delivery_streams';

    /**
     * How often a join reads the stream again after another dispatch took the position it
     * wanted. Each collision means a competing dispatch did commit, so running out of these
     * takes this many dispatches into one stream within the same moment.
     */
    public const int JOIN_TRIES = 10;

    /**
     * Refuse an ordered delivery where it could not keep its order: while the stream table is
     * switched off, and on a queue connection that cannot hold a job back, because a delivery
     * waits for its turn by coming back after a delay.
     *
     * @throws OrderedDeliveryUnavailable
     */
    public static function assertCanOrder(?string $connection, ?string $queue): void
    {
        if (! Config::boolean('webhooks.server.ordering.enabled', false)) {
            throw OrderedDeliveryUnavailable::notEnabled();
        }

        if (! CallWebhookJob::connectionCanHoldBack($connection, $queue)) {
            throw OrderedDeliveryUnavailable::queueCannotWait($connection ?? app(QueueManager::class)->getDefaultDriver());
        }
    }

    /**
     * Take the delivery's place in its stream and queue its job, once the open database
     * transaction commits, or at once outside one.
     *
     * The job is queued after the commit anyway, so the place is taken at that moment too: a
     * dispatch that is rolled back leaves no row behind to hold the stream, and the order of a
     * stream is the order of the commits. When the job then fails to be queued, the place goes
     * back to what it was, so no row is ever left without a job to move it: a new place is left
     * again, and a delivery that held its stream holds it again.
     *
     * @param  Closure(): void  $queue
     */
    public static function queueInStream(string $stream, string $messageId, Closure $queue): void
    {
        /** @var DatabaseTransactionsManager $transactions */
        $transactions = app('db.transactions');

        $transactions->addCallback(static function () use ($stream, $messageId, $queue): void {
            $ledger = app(self::class);
            $place = $ledger->enter($stream, $messageId);

            try {
                $queue();
            } catch (Throwable $failure) {
                if ($place === 'joined') {
                    $ledger->leave($stream, $messageId);
                } elseif ($place === 'reopened') {
                    $ledger->hold($stream, $messageId);
                }

                throw $failure;
            }
        });
    }

    /**
     * Take a place for a delivery that is about to be queued: the end of its stream for a new
     * one, and its own place for one that is in the stream already. A delivery that holds its
     * stream has its hold lifted, because queueing it again means sending it again.
     *
     * @return 'joined'|'reopened'|'kept' what was done: a new place, a hold lifted, or nothing
     */
    public function enter(string $stream, string $messageId): string
    {
        /** @var object{held_at: mixed}|null $place */
        $place = $this->entry($stream, $messageId)->first(['held_at']);

        if ($place === null) {
            $this->join($stream, $messageId);

            return 'joined';
        }

        if ($place->held_at === null) {
            return 'kept';
        }

        $this->entry($stream, $messageId)->update(['held_at' => null]);

        return 'reopened';
    }

    /**
     * Put a delivery at the end of its stream. A delivery that is in the stream already keeps
     * its place.
     */
    public function join(string $stream, string $messageId): void
    {
        $collisions = 0;

        while (true) {
            if ($this->entry($stream, $messageId)->exists()) {
                return;
            }

            $highest = $this->table()->where('stream', $stream)->max('position');

            // In a transaction of its own, which is a savepoint inside one already open: on
            // PostgreSQL a failed statement spoils the transaction it runs in, and the read that
            // follows a collision has to run in one that still works.
            try {
                WebhookConnection::db()->transaction(fn (): bool => $this->table()->insert([
                    'stream' => $stream,
                    'position' => (is_numeric($highest) ? (int) $highest : 0) + 1,
                    'message_id' => $messageId,
                    'created_at' => $this->now(),
                ]));

                return;
            } catch (UniqueConstraintViolationException $collision) {
                if (++$collisions >= self::JOIN_TRIES) {
                    throw $collision;
                }
            }
        }
    }

    /**
     * Where the delivery stands in its stream right now.
     */
    public function turnOf(string $stream, string $messageId): Turn
    {
        /** @var object{message_id: string, held_at: mixed}|null $oldest */
        $oldest = $this->table()->where('stream', $stream)->orderBy('position')->first(['message_id', 'held_at']);

        if ($oldest !== null && $oldest->message_id === $messageId) {
            return $oldest->held_at === null ? Turn::Mine : Turn::Held;
        }

        return $this->entry($stream, $messageId)->exists() ? Turn::Waiting : Turn::Gone;
    }

    /**
     * Take the delivery out of its stream, after it succeeded or because it is skipped, so the
     * next one of the stream goes out.
     */
    public function leave(string $stream, string $messageId): void
    {
        $this->entry($stream, $messageId)->delete();
    }

    /**
     * Mark the delivery as ended without success. It keeps its place, so the deliveries behind
     * it wait until it is resent or skipped.
     */
    public function hold(string $stream, string $messageId): void
    {
        $this->entry($stream, $messageId)->whereNull('held_at')->update(['held_at' => $this->now()]);
    }

    private function entry(string $stream, string $messageId): Builder
    {
        return $this->table()->where('stream', $stream)->where('message_id', $messageId);
    }

    private function table(): Builder
    {
        return WebhookConnection::db()->table(self::TABLE);
    }

    /**
     * The current moment, written the way the stream table's engine stores an instant: with its
     * offset on PostgreSQL, as naive UTC on MySQL.
     */
    private function now(): string
    {
        return Timestamp::forDialect(WebhookConnection::dialect(), Date::now());
    }
}
