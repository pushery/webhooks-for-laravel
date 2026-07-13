<?php

declare(strict_types=1);

namespace Webhooks\Platform\Health;

use Illuminate\Support\Facades\Config;

/**
 * The coarse health band an endpoint falls into, derived from its numeric score.
 * Unknown means there is not yet enough recent history to judge the endpoint.
 */
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failing = 'failing';
    case Unknown = 'unknown';

    /**
     * Map a 0-100 score onto a band. A null score (no recent history) is Unknown;
     * otherwise the band is chosen from the configured score thresholds
     * (webhooks.platform.health.thresholds), so an operator can retune the cut-offs
     * without touching code.
     */
    public static function fromScore(?int $score): self
    {
        if ($score === null) {
            return self::Unknown;
        }

        if ($score >= Config::integer('webhooks.platform.health.thresholds.healthy', 90)) {
            return self::Healthy;
        }

        if ($score >= Config::integer('webhooks.platform.health.thresholds.degraded', 60)) {
            return self::Degraded;
        }

        return self::Failing;
    }
}
