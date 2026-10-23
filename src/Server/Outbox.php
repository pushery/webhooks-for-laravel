<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use JsonException;
use Pushery\Webhooks\Core\Http\ErrorMessageRedactor;
use Pushery\Webhooks\Enums\OutboxEntryState;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Pushery\Webhooks\Server\Exceptions\OutboxUnavailable;
use Pushery\Webhooks\Server\Jobs\RelayOutboxJob;
use Pushery\Webhooks\Server\Models\WebhookOutboxEntry;
use Pushery\Webhooks\Support\Settings;
use Pushery\Webhooks\Support\WebhookConnection;
use Pushery\Webhooks\Testing\FakedWebhook;
use Pushery\Webhooks\Testing\WebhookFake;

/**
 * The transactional outbox: a webhook written in the transaction of the change it reports, and
 * sent once that transaction has committed.
 *
 * A webhook dispatched from inside a transaction is queued after the commit, so a rollback sends
 * nothing. What that cannot cover is the moment after the commit: a queue that refuses the job, or
 * a process that dies before it pushes it, loses the webhook while the change stays. The outbox
 * closes that gap. The entry is a row in the same transaction as the change, so the two exist
 * together or not at all, and a row that never got its job is found by the `webhooks:outbox`
 * sweep, which the Server layer schedules every minute.
 *
 *     DB::transaction(function () use ($order, $endpoint, $outbox): void {
 *         $order->markPaid();
 *
 *         $outbox->record(
 *             WebhookSender::to($endpoint->url)
 *                 ->payload(['order' => $order->id, 'status' => 'paid'])
 *                 ->useSecret($endpoint->secret)
 *                 ->orderedBy("order:{$order->id}"),
 *         );
 *     });
 *
 * Every attempt sends the same bytes under the same message id, so a receiver recognizes a repeat
 * by its `webhook-id`. The outbox itself decides when an attempt is repeated: one attempt per
 * turn through the delivery engine, then a retry after the delivery's backoff, until the receiver
 * takes it, refuses it for good, or webhooks.server.outbox.max_attempts runs out.
 *
 * Resolve it from the container, or construct it; it holds no state of its own.
 */
final readonly class Outbox
{
    /** The counters that hand out each stream's next sequence, one row per stream. */
    public const string STREAMS_TABLE = 'webhook_outbox_streams';

    /**
     * Write the webhook into the outbox, in the transaction that is open on the connection the
     * package stores its tables on, and return the entry.
     *
     * It goes out once that transaction commits: a job for it is queued after the commit, onto the
     * queue and connection the Server layer is configured with, and the scheduled sweep sends what
     * such a job missed. A rollback takes the entry with it, and nothing is sent.
     *
     * An ordering key puts the entry into a stream with every other entry to the same URL under
     * the same key. The entries of a stream go out in the order their transactions committed, each
     * once every earlier one was delivered or skipped, and two transactions recording into one
     * stream take turns at its counter until the first one ends.
     *
     * A test that switched the fake on records the delivery here and writes no entry, the way
     * `dispatch()` records one and queues nothing. The entry handed back is then unsaved.
     *
     * @throws OutboxUnavailable when the outbox is switched off, or no transaction is open on the package's connection
     * @throws JsonException
     */
    public function record(PendingWebhook $webhook): WebhookOutboxEntry
    {
        if (! Config::boolean('webhooks.server.outbox.enabled', false)) {
            throw OutboxUnavailable::notEnabled();
        }

        if (WebhookConnection::db()->transactionLevel() < 1) {
            throw OutboxUnavailable::outsideTransaction(WebhookConnection::name());
        }

        $data = $webhook->toDeliveryData();
        $fake = WebhookFake::active();

        if ($fake instanceof WebhookFake) {
            $fake->record(FakedWebhook::sentThroughTheServer($data));

            return $this->entryFor($data, null);
        }

        $stream = $data->stream();
        $entry = $this->entryFor($data, $stream === null ? null : $this->nextSequence($stream));
        $entry->save();

        $this->relay($entry);

        return $entry;
    }

    /**
     * Send an entry again from the start: pending, due at once, no attempt counted and no alarm
     * raised yet. An entry of a stream takes its own place again, so the entries behind it wait for
     * it as before.
     */
    public function resend(WebhookOutboxEntry $entry): void
    {
        $entry->forceFill([
            'state' => OutboxEntryState::Pending,
            'attempts' => 0,
            'next_attempt_at' => Date::now(),
            'claimed_by' => null,
            'last_status' => null,
            'last_error' => null,
            'alarmed_at' => null,
            'ended_at' => null,
        ])->save();

        $this->relay($entry);
    }

    /**
     * Take an entry out of its stream unsent, so the entries behind it go out. A delivered entry
     * stays delivered.
     */
    public function skip(WebhookOutboxEntry $entry): void
    {
        if ($entry->state === OutboxEntryState::Delivered) {
            return;
        }

        $entry->forceFill([
            'state' => OutboxEntryState::Skipped,
            'next_attempt_at' => null,
            'claimed_by' => null,
            'ended_at' => Date::now(),
        ])->save();

        if ($entry->stream !== null) {
            $this->relay($entry);
        }
    }

    /**
     * Queue a turn of the relay for the entry: for its stream when it has one, for the entry alone
     * when it has none. The job leaves after the open transaction commits, and with it when it is
     * rolled back.
     */
    private function relay(WebhookOutboxEntry $entry): void
    {
        $settings = new Settings;
        $job = new RelayOutboxJob($entry->stream, $entry->stream === null ? $entry->message_id : null);
        $job->onQueue($settings->queue());
        $job->onConnection($settings->connection());

        dispatch($job);
    }

    /**
     * The next sequence of the stream, in commit order.
     *
     * The counter row is updated in the open transaction, which keeps it locked until that
     * transaction ends: a second transaction recording into the same stream waits at the update
     * and reads the counter the first one committed. So a sequence is handed out only after every
     * lower one of its stream is committed or rolled back, and the relay cannot send an entry
     * while an earlier one of its stream is still being written.
     */
    private function nextSequence(string $stream): int
    {
        $connection = WebhookConnection::db();

        $connection->table(self::STREAMS_TABLE)->insertOrIgnore(['stream' => $stream, 'last_sequence' => 0]);
        $connection->table(self::STREAMS_TABLE)->where('stream', $stream)->increment('last_sequence');

        $sequence = $connection->table(self::STREAMS_TABLE)->where('stream', $stream)->value('last_sequence');

        return is_numeric($sequence) ? (int) $sequence : 1;
    }

    /**
     * A new, unsaved entry for the delivery: pending and due at once, with the delivery sealed
     * under the application key and the URL stored without the credentials it may carry.
     */
    private function entryFor(WebhookDeliveryData $data, ?int $sequence): WebhookOutboxEntry
    {
        $entry = WebhookOutboxEntry::resolve();

        $entry->forceFill([
            'message_id' => $data->messageId,
            'stream' => $data->stream(),
            'sequence' => $sequence,
            'url' => ErrorMessageRedactor::url($data->url),
            'event_type' => $data->eventType,
            'meta' => $data->meta,
            'delivery' => Crypt::encrypt($data),
            'state' => OutboxEntryState::Pending,
            'attempts' => 0,
            'next_attempt_at' => Date::now(),
        ]);

        return $entry;
    }
}
