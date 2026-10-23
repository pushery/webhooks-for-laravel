<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Outbox;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Pushery\Webhooks\Core\Http\ErrorMessageRedactor;
use Pushery\Webhooks\Core\Http\TransportResponse;
use Pushery\Webhooks\Enums\OutboxEntryState;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Pushery\Webhooks\Server\Delivery\DeliveryGate;
use Pushery\Webhooks\Server\Delivery\DeliveryPipeline;
use Pushery\Webhooks\Server\Delivery\Disposition;
use Pushery\Webhooks\Server\Delivery\RetryAfter;
use Pushery\Webhooks\Server\Events\WebhookAttemptFailed;
use Pushery\Webhooks\Server\Events\WebhookAttemptRetrying;
use Pushery\Webhooks\Server\Events\WebhookAttemptsExhausted;
use Pushery\Webhooks\Server\Events\WebhookAttemptStarting;
use Pushery\Webhooks\Server\Events\WebhookAttemptSucceeded;
use Pushery\Webhooks\Server\Events\WebhookOutboxEntryGivenUp;
use Pushery\Webhooks\Server\Events\WebhookOutboxEntryOverdue;
use Pushery\Webhooks\Server\Events\WebhookOutboxEntryRefused;
use Pushery\Webhooks\Server\Exceptions\DeliveryRefused;
use Pushery\Webhooks\Server\Models\WebhookOutboxEntry;
use Throwable;
use UnexpectedValueException;

/**
 * Sends the entries of the transactional outbox that are due: one attempt each through the
 * {@see DeliveryPipeline}, and the outcome written back onto the entry.
 *
 * Only the head of a stream is due: an entry whose earlier entries in its stream were all
 * delivered or skipped. An entry outside a stream goes out on its own. Each entry is claimed before
 * it is sent, by a conditional update that moves its next attempt past the claim window, so two
 * relays running at once never send the same entry, and a relay that dies mid-attempt leaves the
 * entry to the next turn once the claim has run out. Nothing holds a transaction open across an
 * HTTP request.
 *
 * The attempt raises the same events a queued delivery does, so the standalone delivery log, the
 * telemetry and a host's own listeners see an outbox attempt like any other. The outbox adds its
 * own three for what only it decides: an entry refused, one given up, one overdue.
 *
 * @internal
 */
final readonly class OutboxRelay
{
    /** How many due entries one read takes. */
    public const int BATCH = 100;

    /** How long a turn keeps starting attempts, in seconds. */
    public const int TURN_SECONDS = 50;

    /**
     * How long a claimed entry stays out of every other relay's reach, in seconds. An attempt is
     * over long before; only a relay that died during one leaves its claim to run out.
     */
    public const int CLAIM_SECONDS = 300;

    public function __construct(
        private DeliveryPipeline $pipeline,
        private DeliveryGate $gate,
    ) {}

    /**
     * Send what is due across the whole outbox, the longest-waiting first.
     *
     * @return int how many entries were attempted
     */
    public function relayDue(): int
    {
        return $this->relay(static fn (Builder $query): Builder => $query);
    }

    /**
     * Send what is due in one stream: its head, and each next one as the one before it is delivered.
     *
     * @return int how many entries were attempted
     */
    public function relayStream(string $stream): int
    {
        return $this->relay(static fn (Builder $query): Builder => $query->where('stream', $stream));
    }

    /**
     * Send one entry outside a stream, if it is due.
     *
     * @return int how many entries were attempted
     */
    public function relayEntry(string $messageId): int
    {
        return $this->relay(static fn (Builder $query): Builder => $query->where('message_id', $messageId));
    }

    /**
     * Attempt each due entry the scope allows, once per turn: a retry the backoff scheduled at once
     * waits for the next turn, so a failing receiver is not asked twice in a row by the same turn.
     *
     * @param  Closure(Builder<WebhookOutboxEntry>): Builder<WebhookOutboxEntry>  $scope
     */
    private function relay(Closure $scope): int
    {
        $until = Date::now()->addSeconds(self::TURN_SECONDS);

        /** @var array<int, true> $attempted */
        $attempted = [];

        do {
            $progress = false;

            foreach ($this->due($scope) as $entry) {
                if (isset($attempted[$entry->id])) {
                    continue;
                }

                if (Date::now()->greaterThanOrEqualTo($until)) {
                    return count($attempted);
                }

                $token = $this->claim($entry);

                if ($token === null) {
                    continue;
                }

                $attempted[$entry->id] = true;
                $progress = true;

                $this->send($entry, $token);
            }
        } while ($progress);

        return count($attempted);
    }

    /**
     * The due heads the scope allows, the longest-waiting first.
     *
     * @param  Closure(Builder<WebhookOutboxEntry>): Builder<WebhookOutboxEntry>  $scope
     * @return Collection<int, WebhookOutboxEntry>
     */
    private function due(Closure $scope): Collection
    {
        $model = WebhookOutboxEntry::model();
        $table = new $model()->getTable();

        return $scope($model::query())
            ->where('state', OutboxEntryState::Pending->value)
            ->whereTimestamp('next_attempt_at', '<=', Date::now())
            ->where(static function (Builder $head) use ($table): void {
                $head->whereNull('stream')->orWhereNotExists(static function (QueryBuilder $earlier) use ($table): void {
                    $earlier->from($table, 'earlier')
                        ->whereColumn('earlier.stream', $table.'.stream')
                        ->whereColumn('earlier.sequence', '<', $table.'.sequence')
                        ->whereIn('earlier.state', OutboxEntryState::holding());
                });
            })
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();
    }

    /**
     * Claim the entry for this turn and return the claim's token, or null when another relay was
     * faster or the entry is no longer due.
     */
    private function claim(WebhookOutboxEntry $entry): ?string
    {
        $token = (string) Str::uuid7();

        $claimed = WebhookOutboxEntry::model()::query()
            ->whereKey($entry->getKey())
            ->where('state', OutboxEntryState::Pending->value)
            ->whereTimestamp('next_attempt_at', '<=', Date::now())
            ->update([
                'claimed_by' => $token,
                'next_attempt_at' => $entry->fromDateTime(Date::now()->addSeconds(self::CLAIM_SECONDS)),
            ]);

        if ($claimed !== 1) {
            return null;
        }

        return $token;
    }

    private function send(WebhookOutboxEntry $entry, string $token): void
    {
        $attempt = $entry->attempts + 1;
        $data = $entry->deliveryData();

        if (! $data instanceof WebhookDeliveryData) {
            $exception = new UnexpectedValueException('The entry can no longer be unsealed: the application key it was sealed with is gone.');

            if ($this->end($entry, $token, OutboxEntryState::GivenUp, $entry->attempts, null, $exception)) {
                event(new WebhookOutboxEntryGivenUp($entry, null, $exception));
            }

            return;
        }

        // Asked the moment before the attempt, as a queued delivery asks it: an endpoint disabled
        // or removed while the entry waited must not receive it.
        $refusal = $this->gate->refusalFor($data);

        if ($refusal !== null) {
            $this->refuse($entry, $token, $data, $attempt, null, DeliveryRefused::because($refusal));

            return;
        }

        event(new WebhookAttemptStarting($data, $attempt));

        $outcome = $this->pipeline->attempt($data);
        $response = $outcome->response;

        if ($outcome->disposition === Disposition::Succeeded && $response instanceof TransportResponse) {
            $this->end($entry, $token, OutboxEntryState::Delivered, $attempt, $response, null);

            event(new WebhookAttemptSucceeded($data, $attempt, $response));

            return;
        }

        if ($outcome->disposition !== Disposition::Retryable) {
            $this->refuse($entry, $token, $data, $attempt, $response, $outcome->exception);

            return;
        }

        event(new WebhookAttemptFailed($data, $attempt, $response, $outcome->exception));

        $maximum = $this->maximumAttempts();

        if ($maximum !== null && $attempt >= $maximum) {
            $ended = $this->end($entry, $token, OutboxEntryState::GivenUp, $attempt, $response, $outcome->exception);

            event(new WebhookAttemptsExhausted($data, $attempt, $response, $outcome->exception));

            if ($ended) {
                event(new WebhookOutboxEntryGivenUp($entry, $response, $outcome->exception));
            }

            return;
        }

        $hint = $data->options->respectRetryAfter && $response instanceof TransportResponse ? RetryAfter::from($response) : null;
        $delay = $data->backoff->delayAfterAttempt($attempt, $hint);

        $retried = $this->settle($entry, $token, [
            'attempts' => $attempt,
            'next_attempt_at' => $entry->fromDateTime(Date::now()->addSeconds($delay)),
            'last_status' => $response?->status,
            'last_error' => $this->errorFrom($outcome->exception),
        ]);

        event(new WebhookAttemptRetrying($data, $attempt, $delay));

        if ($retried) {
            $this->alarmIfOverdue($entry);
        }
    }

    /**
     * The entry was refused, by the receiver or by the delivery gate: it ends, keeps its stream
     * back, and says so once.
     */
    private function refuse(
        WebhookOutboxEntry $entry,
        string $token,
        WebhookDeliveryData $data,
        int $attempt,
        ?TransportResponse $response,
        ?Throwable $exception,
    ): void {
        $ended = $this->end($entry, $token, OutboxEntryState::Refused, $attempt, $response, $exception);

        event(new WebhookAttemptFailed($data, $attempt, $response, $exception));
        event(new WebhookAttemptsExhausted($data, $attempt, $response, $exception));

        if ($ended) {
            event(new WebhookOutboxEntryRefused($entry, $response, $exception));
        }
    }

    /**
     * Write the end of the entry: delivered, refused or given up. Returns whether it was written,
     * which it is not when the entry was resent or skipped while this turn was sending it.
     */
    private function end(
        WebhookOutboxEntry $entry,
        string $token,
        OutboxEntryState $state,
        int $attempts,
        ?TransportResponse $response,
        ?Throwable $exception,
    ): bool {
        return $this->settle($entry, $token, [
            'state' => $state->value,
            'attempts' => $attempts,
            'next_attempt_at' => null,
            'last_status' => $response?->status,
            'last_error' => $state === OutboxEntryState::Delivered ? null : $this->errorFrom($exception),
            'ended_at' => $entry->fromDateTime(Date::now()),
        ]);
    }

    /**
     * Write the outcome onto the entry, if this turn still holds its claim, and read the entry back
     * so whoever hears about it next sees what was stored.
     *
     * @param  array{state?: string, attempts: int, next_attempt_at: string|null, last_status: int|null, last_error: string|null, ended_at?: string|null}  $values
     */
    private function settle(WebhookOutboxEntry $entry, string $token, array $values): bool
    {
        $settled = WebhookOutboxEntry::model()::query()
            ->whereKey($entry->getKey())
            ->where('claimed_by', $token)
            ->where('state', OutboxEntryState::Pending->value)
            ->update([...$values, 'claimed_by' => null]) === 1;

        $entry->refresh();

        return $settled;
    }

    /**
     * Raise the alarm once for an entry that is still not delivered after the configured hours.
     */
    private function alarmIfOverdue(WebhookOutboxEntry $entry): void
    {
        $hours = Config::integer('webhooks.server.outbox.alarm_after_hours', 72);

        if ($hours < 1 || $entry->alarmed_at !== null || $entry->created_at === null) {
            return;
        }

        if ($entry->created_at->copy()->addHours($hours)->isFuture()) {
            return;
        }

        $raised = WebhookOutboxEntry::model()::query()
            ->whereKey($entry->getKey())
            ->whereNull('alarmed_at')
            ->update(['alarmed_at' => $entry->fromDateTime(Date::now())]) === 1;

        if ($raised) {
            $entry->refresh();

            event(new WebhookOutboxEntryOverdue($entry));
        }
    }

    /**
     * The configured most attempts, or null to keep trying until the receiver takes the entry or
     * refuses it for good.
     */
    private function maximumAttempts(): ?int
    {
        $maximum = Config::get('webhooks.server.outbox.max_attempts');

        if (! is_int($maximum) || $maximum < 1) {
            return null;
        }

        return $maximum;
    }

    /**
     * The stored text of a failure, with any credential in a URL it names taken out first.
     */
    private function errorFrom(?Throwable $exception): ?string
    {
        $message = $exception?->getMessage();

        return $message === null || $message === '' ? null : ErrorMessageRedactor::redact($message);
    }
}
