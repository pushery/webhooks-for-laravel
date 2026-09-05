<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Enums;

/**
 * Lifecycle state of a single delivery-log entry.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Exhausted = 'exhausted';

    /**
     * Never sent, because the endpoint was disabled or deleted while this delivery sat in the
     * queue. Terminal like {@see self::Exhausted} and deliberately not the same thing: the
     * endpoint never answered, and nothing about it can be read from the outcome.
     *
     * That distinction is what the circuit breaker has always made — it declines to charge a
     * refusal to the failure streak because refusing is "our own decision, not the endpoint's
     * fault" — while the health score, answering the same question about the same endpoint, was
     * counting them. So re-enabling an endpoint reset the streak and left the score depressed
     * for the rest of the health window, with every delivery since then successful.
     */
    case Refused = 'refused';

    /**
     * The design-system intent a badge should carry for this status.
     *
     * ONE ladder, because four copies of it had already become three different ladders. The
     * WireKit console stub mapped `exhausted` to `warning` — the same amber it uses for
     * `pending` — while the dashboard and the portal mapped it to `danger`, so the worst
     * outcome a delivery has read one step too harmless on the surface a host publishes and
     * restyles. And the dashboard's own delivery table had no `refused` arm at all, so a
     * delivery that was never sent fell through to amber there and gray beside it.
     *
     * The portal's copy carried a comment stating the rule — "exhausted is danger, not warning.
     * Two surfaces disagreeing about which outcome is grave is a difference a reader would have
     * to learn" — which is exactly what the stub was doing. A rule written in one of four copies
     * governs one of four copies.
     *
     * `Refused` is neutral rather than dangerous on purpose, and for the reason its own docblock
     * gives: the endpoint never answered, so the outcome says nothing about the endpoint. It is
     * terminal without being a failure of the destination.
     *
     * @return 'success'|'danger'|'warning'|'neutral'
     */
    public function intent(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed, self::Exhausted => 'danger',
            self::Refused => 'neutral',
            self::Pending => 'warning',
        };
    }
}
