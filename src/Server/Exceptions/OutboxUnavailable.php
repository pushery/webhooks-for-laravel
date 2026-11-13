<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Exceptions;

use LogicException;

/**
 * A delivery was handed to the transactional outbox where the outbox cannot keep its promise.
 *
 * Thrown by `Outbox::record()` before anything is written, because each case would otherwise
 * surface later as a delivery that went out for a change that never happened, or never went out
 * for one that did. Thrown by `Outbox::resend()` before the entry is touched, when the delivery it
 * is handed would no longer be the message the entry's receiver and its stream know.
 */
final class OutboxUnavailable extends LogicException
{
    public static function notEnabled(): self
    {
        return new self(
            'The transactional outbox keeps its entries in the webhook_outbox table, which the Server layer creates '
            .'while webhooks.server.outbox.enabled is true. Turn that on and migrate, then record again.',
        );
    }

    public static function outsideTransaction(?string $connection): self
    {
        $name = $connection ?? 'default';

        return new self(
            "The outbox writes an entry in the transaction of the change it reports, and no transaction is open on the `{$name}` "
            .'connection the package stores its tables on. Record inside the transaction that writes the change. '
            .'Where webhooks.database.connection names a connection of its own, the change has to run on that connection too.',
        );
    }

    public static function resentUnderAnotherMessageId(string $entry, string $webhook): self
    {
        return new self(
            "The outbox entry {$entry} is sent again under its own message id, and the webhook handed to resend() carries {$webhook}. "
            ."Give the webhook the entry's id with useMessageId(\$entry->message_id).",
        );
    }

    public static function resentIntoAnotherStream(string $entry): self
    {
        return new self(
            "The outbox entry {$entry} is sent again at its place in its stream, and the webhook handed to resend() belongs to "
            .'another stream: its URL or its ordering key is not the one the entry was recorded with. Send it to the same URL '
            .'under the same ordering key, or record a new entry.',
        );
    }
}
