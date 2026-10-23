<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Server\Outbox\OutboxRelay;

/**
 * Sends every entry of the transactional outbox that is due, the longest-waiting first.
 *
 * Each recorded entry queues a turn of its own after its transaction commits, so on a healthy queue
 * this sweep finds little. It is the net under that path: an entry whose job the queue refused, or
 * whose process died before pushing it, a retry whose time has come, and the next entry of a stream
 * whose head was skipped. Scheduled every minute while webhooks.server.outbox.enabled is on; with
 * the outbox off it says so and touches no table.
 *
 * @internal
 */
final class RelayOutboxCommand extends Command
{
    protected $signature = 'webhooks:outbox';

    protected $description = 'Send the entries of the transactional webhook outbox that are due.';

    public function handle(OutboxRelay $relay): int
    {
        if (! Config::boolean('webhooks.server.outbox.enabled', false)) {
            $this->info('The transactional outbox is switched off (webhooks.server.outbox.enabled), so there is nothing to send.');

            return self::SUCCESS;
        }

        $attempted = $relay->relayDue();

        $this->info(sprintf('Attempted %d outbox %s.', $attempted, $attempted === 1 ? 'entry' : 'entries'));

        return self::SUCCESS;
    }
}
