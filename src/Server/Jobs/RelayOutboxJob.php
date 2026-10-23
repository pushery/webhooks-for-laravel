<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Jobs;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Pushery\Webhooks\Server\Outbox\OutboxRelay;
use Pushery\Webhooks\Support\Settings;

/**
 * A turn of the outbox relay for one stream, or for one entry outside a stream.
 *
 * It is queued when an entry is recorded, resent or skipped, after the transaction that did so
 * commits, and dropped with that transaction when it is rolled back. It sends what is due there and
 * stops. The scheduled `webhooks:outbox` sweep sends whatever a turn left behind, so a turn that
 * never ran costs a minute at most and loses nothing.
 *
 * @internal
 */
final class RelayOutboxJob implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * One try. A turn that dies leaves its entries due, or claimed until their claim runs out, and
     * the sweep sends them; a second try of the same turn would only race it.
     */
    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly ?string $stream,
        public readonly ?string $messageId,
    ) {
        $settings = new Settings;

        // The turn stops starting attempts once its time is up, and the attempt it started last
        // may still run its whole HTTP budget after that, so the job outlives both.
        $this->timeout = OutboxRelay::TURN_SECONDS + max(
            CallWebhookJob::MINIMUM_TIMEOUT,
            $settings->connectTimeout() + $settings->timeout() + CallWebhookJob::TIMEOUT_HEADROOM,
        );
    }

    public function handle(OutboxRelay $relay): void
    {
        if ($this->stream !== null) {
            $relay->relayStream($this->stream);

            return;
        }

        if ($this->messageId !== null) {
            $relay->relayEntry($this->messageId);
        }
    }
}
