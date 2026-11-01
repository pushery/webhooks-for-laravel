<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Console;

use Illuminate\Console\Command;
use Pushery\Webhooks\Database\OwnerKeyConversion;
use Pushery\Webhooks\Database\OwnerKeyType;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * Changes the owner_id columns to `webhooks.platform.owner_key_type` while the tables are empty.
 *
 * The type is fixed when the tables are migrated. An installation that chose it later, such as an
 * application that starts keying webhook endpoints by a UUID workspace after its first deploy, runs
 * this once: the subscriptions table, the delivery log and the dashboard rollup then have the columns a
 * fresh migration with the setting creates. With a row in any of them it changes nothing and exits
 * with 1, because a row's key is of the old type. Setting the old type back and running it again is
 * the way back.
 *
 * @internal
 */
final class OwnerKeyTypeCommand extends Command
{
    protected $signature = 'webhooks:owner-key-type
        {--dry-run : Report which columns would change, and whether the tables are empty, without changing anything}';

    protected $description = 'Change the owner_id columns to the configured owner_key_type while the webhook tables are empty.';

    public function handle(): int
    {
        $target = OwnerKeyType::fromConfig();
        $conversion = new OwnerKeyConversion(WebhookConnection::name());
        $pending = $conversion->pending($target);

        if ($pending === []) {
            $this->info(sprintf('owner_id is already "%s" on every table that carries it. Nothing to change.', $target->value));

            return self::SUCCESS;
        }

        foreach ($pending as $table => $type) {
            $this->line(sprintf('%s.owner_id is %s and changes to "%s".', $table, $type, $target->value));
        }

        if ($conversion->rebuildsRollupView($target)) {
            $this->line(sprintf('%s is dropped and created again from its own definition, over the new column.', OwnerKeyConversion::ROLLUP));
        }

        $occupied = $conversion->occupied();

        if ($occupied !== []) {
            $this->error(sprintf(
                '%s %s rows. owner_id changes its type only while these tables are empty, because the keys they hold are of the old type. Nothing was changed.',
                implode(' and ', $occupied),
                count($occupied) === 1 ? 'holds' : 'hold',
            ));

            return self::FAILURE;
        }

        if ($this->option('dry-run') === true) {
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $conversion->convert($target);

        $this->info(sprintf('owner_id is now "%s" on every table that carries it.', $target->value));

        return self::SUCCESS;
    }
}
