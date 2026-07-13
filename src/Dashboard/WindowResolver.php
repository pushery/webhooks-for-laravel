<?php

declare(strict_types=1);

namespace Webhooks\Dashboard;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Maps a dashboard window token ('24h' / '7d' / '30d') to a bounded time range.
 * The metrics query object is constructed with the resulting interval and never
 * scans beyond it.
 *
 * @internal
 */
final class WindowResolver
{
    /**
     * Every token this resolver can turn into an interval — the outer bound of what any
     * dashboard surface (the page control, the JSON endpoint) may offer.
     */
    public const array SUPPORTED = ['24h', '7d', '30d'];

    /**
     * The selectable window tokens: the configured list narrowed to the tokens the
     * resolver actually supports, so a token a host's config does not name — or names
     * wrongly — is never offered and can never reach interval(). Falls back to the full
     * supported set when the configured list leaves nothing usable.
     *
     * @return non-empty-list<string>
     */
    public static function allowed(): array
    {
        $tokens = [];

        foreach (Config::array('webhooks.dashboard.windows', self::SUPPORTED) as $token) {
            if (is_string($token) && in_array($token, self::SUPPORTED, true)) {
                $tokens[] = $token;
            }
        }

        return $tokens === [] ? self::SUPPORTED : $tokens;
    }

    /**
     * The window token as a duration back from now.
     */
    public static function interval(string $window): CarbonInterval
    {
        return match ($window) {
            '24h' => CarbonInterval::hours(24),
            '7d' => CarbonInterval::days(7),
            '30d' => CarbonInterval::days(30),
            default => throw new InvalidArgumentException(
                "Unsupported dashboard window [{$window}]; expected one of 24h, 7d, 30d."
            ),
        };
    }

    /**
     * The inclusive lower bound of the window: now minus the resolved interval.
     */
    public static function from(string $window): CarbonImmutable
    {
        return CarbonImmutable::now()->sub(self::interval($window));
    }
}
