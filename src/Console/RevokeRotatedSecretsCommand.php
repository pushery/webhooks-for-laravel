<?php

declare(strict_types=1);

namespace Webhooks\Console;

use Illuminate\Console\Command;
use Webhooks\Models\WebhookSubscription;
use Webhooks\WebhookManager;

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

        WebhookSubscription::query()
            ->whereNotNull('previous_secret')
            ->eachById(function (WebhookSubscription $subscription) use ($manager, &$revoked): void {
                if ($manager->revokeExpiredSecret($subscription)) {
                    $revoked++;
                }
            });

        $this->info(sprintf('Revoked %d rotated-away endpoint secret(s) whose window had closed.', $revoked));

        return self::SUCCESS;
    }
}
