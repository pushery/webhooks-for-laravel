<?php

declare(strict_types=1);

namespace Webhooks\Server\Listeners;

use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Throwable;
use Webhooks\Enums\DeliveryStatus;
use Webhooks\Server\Data\WebhookDeliveryData;
use Webhooks\Server\Events\WebhookAttemptFailed;
use Webhooks\Server\Events\WebhookAttemptRetrying;
use Webhooks\Server\Events\WebhookAttemptsExhausted;
use Webhooks\Server\Events\WebhookAttemptStarting;
use Webhooks\Server\Events\WebhookAttemptSucceeded;
use Webhooks\Server\Events\WebhookDeliveryDispatching;
use Webhooks\Server\Models\WebhookServerDelivery;
use Webhooks\Support\WebhookConnection;

/**
 * The standalone counterpart to the Platform delivery-log subscriber: it maps the
 * six Server delivery events onto a single webhook_server_deliveries row per
 * message, so an app driving the Server layer WITHOUT the Platform layer still gets
 * a persisted, prunable record of every delivery.
 *
 * Every handler locates its row by the stable message id and is idempotent: a row
 * already in a terminal state (Succeeded or Exhausted) is never touched again, so a
 * duplicated or out-of-order event — the known at-least-once queue edge case —
 * can neither downgrade a success nor reopen a finished delivery. Registered only
 * when webhooks.server.persistence.enabled; while off, nothing here ever runs.
 *
 * @internal
 */
final class PersistServerDelivery
{
    private const string DEFAULT_ERROR = 'Webhook delivery failed.';

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(WebhookDeliveryDispatching::class, self::onDispatching(...));
        $events->listen(WebhookAttemptStarting::class, self::onDelivering(...));
        $events->listen(WebhookAttemptSucceeded::class, self::onSucceeded(...));
        $events->listen(WebhookAttemptFailed::class, self::onFailed(...));
        $events->listen(WebhookAttemptRetrying::class, self::onRetrying(...));
        $events->listen(WebhookAttemptsExhausted::class, self::onFailedFinally(...));
    }

    public function onDispatching(WebhookDeliveryDispatching $event): void
    {
        $this->record($event->data, DeliveryStatus::Pending);
    }

    public function onDelivering(WebhookAttemptStarting $event): void
    {
        $this->record($event->data, DeliveryStatus::Pending, ['attempt' => $event->attempt]);
    }

    public function onSucceeded(WebhookAttemptSucceeded $event): void
    {
        $this->record($event->data, DeliveryStatus::Succeeded, [
            'attempt' => $event->attempt,
            'http_status' => $event->response->status,
            'duration_ms' => $event->response->durationMs,
            'delivered_at' => Date::now(),
            'error' => null,
        ]);
    }

    public function onFailed(WebhookAttemptFailed $event): void
    {
        $this->record($event->data, DeliveryStatus::Failed, [
            'attempt' => $event->attempt,
            'http_status' => $event->response?->status,
            'duration_ms' => $event->response?->durationMs,
            'error' => $this->errorFrom($event->exception),
        ]);
    }

    public function onRetrying(WebhookAttemptRetrying $event): void
    {
        // A retry is queued; reopen the row to pending while keeping the recorded
        // attempt and last error until the next attempt overwrites them.
        $this->record($event->data, DeliveryStatus::Pending, ['attempt' => $event->attempt]);
    }

    public function onFailedFinally(WebhookAttemptsExhausted $event): void
    {
        $this->record($event->data, DeliveryStatus::Exhausted, [
            'attempt' => $event->attempt,
            'http_status' => $event->response?->status,
            'duration_ms' => $event->response?->durationMs,
            'error' => $this->errorFrom($event->exception),
        ]);
    }

    /**
     * Upsert the row for a message: create it on first sight, otherwise update it in
     * place — but never disturb a delivery already in a terminal state.
     *
     * @param  array<string, scalar|DeliveryStatus|DateTimeInterface|null>  $attributes
     */
    private function record(WebhookDeliveryData $data, DeliveryStatus $status, array $attributes = [], bool $retry = true): void
    {
        $delivery = WebhookServerDelivery::query()->firstOrNew(['message_id' => $data->messageId]);

        if ($delivery->exists && in_array($delivery->status, [DeliveryStatus::Succeeded, DeliveryStatus::Exhausted], true)) {
            return;
        }

        $delivery->fill([
            'url' => $data->url,
            'event_type' => $data->eventType,
            'tags' => $data->tags,
            'status' => $status,
            ...$attributes,
        ]);

        // An existing row is updated by primary key and can never race the unique
        // message_id. A fresh row is inserted inside a nested transaction, so a lost
        // insert race raises a catchable unique violation that rolls back only the
        // savepoint — never a surrounding transaction — and is then recovered as an
        // in-place update of the row that won the race.
        if ($delivery->exists) {
            $delivery->save();

            return;
        }

        try {
            WebhookConnection::db()->transaction(static fn () => $delivery->save());
        } catch (QueryException $exception) {
            if (! $retry || ! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $this->record($data, $status, $attributes, retry: false);
        }
    }

    /**
     * Whether a query failure is a unique-constraint violation — a lost insert race on the
     * unique message_id, not a real error. Laravel marshals a duplicate-key error into
     * UniqueConstraintViolationException on both PostgreSQL and MySQL, so that is the portable
     * signal. The explicit 23505 string comparison is kept ALONGSIDE it, not replaced: a driver
     * that reports the SQLSTATE as an integer (rather than the string Laravel's own detector
     * requires) is still recognised and recovered rather than surfacing as a hard write error.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception instanceof UniqueConstraintViolationException
            || (string) $exception->getCode() === '23505';
    }

    private function errorFrom(?Throwable $exception): string
    {
        return $exception?->getMessage() ?? self::DEFAULT_ERROR;
    }
}
