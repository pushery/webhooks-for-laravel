<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Console;

use Illuminate\Console\Command;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Health\EndpointHealth;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Recomputes and caches the health score of every active endpoint from its recent
 * delivery history. Run it on a schedule for a continuously fresh status board, or
 * on demand. Unlike the delivery-driven listener it needs no configuration flag —
 * invoking it always refreshes.
 *
 * @internal
 */
#[AsCommand(name: 'webhooks:refresh-endpoint-health')]
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
        // over the rows ends at its first bad row, and the endpoints ordered after it keep the
        // score the last delivery left them with -- which is precisely what this command exists
        // to stop. A thousand rows at a time by key: the database driver buffers the whole result
        // of a cursor, PostgreSQL's outside memory_limit, and the endpoints grow with every host.
        foreach (WebhookSubscription::model()::query()->active()->lazyById(1000) as $subscription) {
            try {
                $health->refresh($subscription);
                $count++;
            } catch (Throwable $failure) {
                $key = $subscription->getKey();

                // As in RevokeRotatedSecretsCommand: the cast is for the declared list type,
                // not for the output. The only reader is the implode() below, which renders an
                // int identically.
                $failed[] = is_scalar($key) ? (string) $key : 'unknown';

                report($failure);
            }
        }

        $this->info(sprintf('Refreshed the health score of %d active endpoint(s).', $count));

        if ($failed !== []) {
            $this->error(OutputFormatter::escape(sprintf(
                '%d endpoint(s) could not be scored and keep whatever their last delivery left: %s.',
                count($failed),
                implode(', ', $failed),
            )));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
