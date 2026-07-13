<?php

declare(strict_types=1);

namespace Webhooks\Console;

use Illuminate\Console\Command;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Platform\Health\EndpointHealth;

/**
 * Recomputes and caches the health score of every active endpoint from its recent
 * delivery history. Run it on a schedule for a continuously fresh status board, or
 * on demand. Unlike the delivery-driven listener it needs no configuration flag —
 * invoking it always refreshes.
 *
 * @internal
 */
final class RefreshEndpointHealthCommand extends Command
{
    protected $signature = 'webhooks:refresh-endpoint-health';

    protected $description = 'Recompute and cache the health score of every active webhook endpoint.';

    public function handle(EndpointHealth $health): int
    {
        $count = 0;

        foreach (WebhookSubscription::query()->active()->cursor() as $subscription) {
            $health->refresh($subscription);
            $count++;
        }

        $this->info(sprintf('Refreshed the health score of %d active endpoint(s).', $count));

        return self::SUCCESS;
    }
}
