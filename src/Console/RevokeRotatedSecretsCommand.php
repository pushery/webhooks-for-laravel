<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Console;

use Illuminate\Console\Command;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\WebhookManager;
use Throwable;

/**
 * Clears every rotated-away signing secret whose rotation window has closed.
 *
 * A delivery revokes its own endpoint's expired secret on the way out, so a busy
 * endpoint needs nothing from this command. A DORMANT one does: without the sweep, an
 * endpoint that stops receiving traffic the day it rotates would keep its old secret —
 * and that secret's signatures — valid indefinitely, which is precisely the state a
 * rotation exists to end. Scheduled hourly while the Platform layer is on.
 *
 * @internal
 */
final class RevokeRotatedSecretsCommand extends Command
{
    protected $signature = 'webhooks:revoke-rotated-secrets';

    protected $description = 'Clear every endpoint secret whose rotation window has closed, so it can no longer sign or verify.';

    public function handle(WebhookManager $manager): int
    {
        $revoked = 0;
        /** @var list<string> $failed */
        $failed = [];

        WebhookSubscription::query()
            ->whereNotNull('previous_secret')
            // Per row, because one unreadable row used to end the whole sweep -- and end it
            // again every hour. `previous_secret` is an `encrypted` cast, so it is decrypted on
            // read, and revokeExpiredSecret() reads it on its first line. A row whose ciphertext
            // cannot be read (a partial import, a truncated value) throws there. eachById()
            // orders by id, so the next hourly run stops at the same row: the sweep is not slow,
            // it is stopped, permanently.
            //
            // What that leaves behind is the exact state rotation exists to end -- a rotated-away
            // signing secret still valid, on every endpoint ordered after the broken row. And it
            // is quiet: scheduler output goes nowhere by default, and a stalled sweep looks like
            // a sweep with nothing to do.
            ->eachById(function (WebhookSubscription $subscription) use ($manager, &$revoked, &$failed): void {
                try {
                    if ($manager->revokeExpiredSecret($subscription)) {
                        $revoked++;
                    }
                } catch (Throwable $failure) {
                    // The id, not the value: whatever could not be decrypted is a secret, and a
                    // log line is the wrong place for one even when it is unreadable.
                    $key = $subscription->getKey();

                    $failed[] = is_scalar($key) ? (string) $key : 'unknown';

                    report($failure);
                }
            });

        $this->info(sprintf('Revoked %d rotated-away endpoint secret(s) whose window had closed.', $revoked));

        if ($failed !== []) {
            $this->error(sprintf(
                '%d endpoint(s) could not be processed and still hold a rotated-away secret: %s. '
                .'Their previous_secret cannot be read -- check APP_KEY and APP_PREVIOUS_KEYS, or '
                .'clear the column for those rows.',
                count($failed),
                implode(', ', $failed),
            ));

            // A non-zero exit is what makes this reachable at all: withoutOverlapping is a skip
            // and scheduler output is discarded by default, so a host's onFailure() hook is the
            // only channel that carries it.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
