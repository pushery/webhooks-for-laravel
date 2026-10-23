<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Enums;

/**
 * Where an entry of the transactional outbox stands.
 *
 * An entry is pending from the moment it is recorded until the receiver takes it or it ends
 * without success. Only {@see self::Delivered} and {@see self::Skipped} let the entries behind it
 * in its stream go out: a refused entry and one the outbox gave up on keep their place, the way an
 * ordered delivery does, until they are sent again or skipped.
 */
enum OutboxEntryState: string
{
    /** Recorded and not yet taken: due at its next attempt, or waiting for its turn in a stream. */
    case Pending = 'pending';

    /** The receiver answered with a 2xx. */
    case Delivered = 'delivered';

    /** The receiver refused it for good, or the delivery gate refused to send it. */
    case Refused = 'refused';

    /** Its attempts ran out while the receiver kept failing in a way that could pass. */
    case GivenUp = 'given_up';

    /** Taken out of its stream unsent, so the entries behind it go out. */
    case Skipped = 'skipped';

    /**
     * Whether an entry in this state keeps the later entries of its stream back.
     */
    public function holdsItsStream(): bool
    {
        return match ($this) {
            self::Pending, self::Refused, self::GivenUp => true,
            self::Delivered, self::Skipped => false,
        };
    }

    /**
     * The states that keep the later entries of a stream back, for a query.
     *
     * @return list<string>
     */
    public static function holding(): array
    {
        $holding = [];

        foreach (self::cases() as $state) {
            if ($state->holdsItsStream()) {
                $holding[] = $state->value;
            }
        }

        return $holding;
    }
}
