<?php

declare(strict_types=1);

namespace Webhooks\Support;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Guards the layers that are built ON the Platform layer. The dashboard reads Platform's
 * delivery log and the self-service portal manages Platform's endpoints, so switching
 * either on while webhooks.platform.enabled is false leaves it pointing at tables whose
 * migrations never ran — and the consumer meets that as a raw `relation
 * "webhook_deliveries" does not exist` from inside a panel query or a materialized-view
 * DDL, with nothing to connect it to the config combination that caused it.
 *
 * This turns that into one clear sentence at boot, naming both switches, in the same
 * voice as `Webhooks\Database\PostgresRequirement`.
 *
 * @internal
 */
final class PlatformRequirement
{
    /**
     * @param  string  $layer  the layer that needs Platform, as a consumer knows it
     * @param  string  $switch  the config key that switched that layer on
     * @param  string  $needs  what it reads or manages, and where that lives
     *
     * @throws RuntimeException when the Platform layer is off
     */
    public static function ensure(string $layer, string $switch, string $needs): void
    {
        if (Config::boolean('webhooks.platform.enabled', true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The webhooks %s %s, which the Platform layer owns — but webhooks.platform.enabled '
            .'is false, so that table is never migrated and nothing it queries exists. Enable the '
            .'Platform layer (webhooks.platform.enabled), or switch this one off (%s).',
            $layer,
            $needs,
            $switch,
        ));
    }
}
