<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Exceptions;

use LogicException;

/**
 * A delivery was handed to the transactional outbox where the outbox cannot keep its promise.
 *
 * Thrown by `Outbox::record()` before anything is written, because each case would otherwise
 * surface later as a delivery that went out for a change that never happened, or never went out
 * for one that did.
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
}
