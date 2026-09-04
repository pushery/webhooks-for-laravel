<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Console;

use Illuminate\Console\Command;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Health\EndpointHealth;
use Throwable;

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
        /** @var list<string> $failed */
        $failed = [];

        // Per row, for the reason spelled out in RevokeRotatedSecretsCommand: an unguarded sweep
        // over a cursor ends at its first bad row, and the endpoints ordered after it keep the
        // score the last delivery left them with -- which is precisely what this command exists
        // to stop.
        foreach (WebhookSubscription::query()->active()->cursor() as $subscription) {
            try {
                $health->refresh($subscription);
                $count++;
            } catch (Throwable $failure) {
                $key = $subscription->getKey();

                $failed[] = is_scalar($key) ? (string) $key : 'unknown';

                report($failure);
            }
        }

        $this->info(sprintf('Refreshed the health score of %d active endpoint(s).', $count));

        if ($failed !== []) {
            $this->error(sprintf(
                '%d endpoint(s) could not be scored and keep whatever their last delivery left: %s.',
                count($failed),
                implode(', ', $failed),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
