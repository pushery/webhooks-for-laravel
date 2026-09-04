<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Console\Scheduling\Event;
use InvalidArgumentException;

/**
 * Maps a configured cadence token onto a scheduled command's frequency. Every
 * background sweep the package schedules — the endpoint-health refresh and the
 * dashboard metrics refresh — reads its cadence from config through this one map, so
 * a token means the same thing everywhere and a new token is added in a single place.
 *
 * A token the package does not support falls back to the caller's default cadence
 * rather than silently never running: a typo in a host's config must never leave a
 * sweep unscheduled.
 *
 * @internal
 */
final class ScheduleCadence
{
    /**
     * The cadence tokens a host may configure, each naming the schedule frequency it
     * applies.
     *
     * @var list<string>
     */
    public const array TOKENS = [
        'everyMinute',
        'everyTwoMinutes',
        'everyThreeMinutes',
        'everyFourMinutes',
        'everyFiveMinutes',
        'everyTenMinutes',
        'everyFifteenMinutes',
        'everyThirtyMinutes',
        'hourly',
    ];

    /**
     * The cadence token in minutes — what "how often does this run" means as a number.
     *
     * Exposed so a reader of the DATA can ask the same question the scheduler answers: a rollup
     * one cadence behind is a rollup between two runs, and only a multiple of it means something
     * broke. An unsupported token falls back to five minutes rather than to zero, because zero
     * would make every comparison against it read as "always late".
     */
    public static function minutesFor(string $cadence): int
    {
        return match (self::supports($cadence) ? $cadence : 'everyFiveMinutes') {
            'everyMinute' => 1,
            'everyTwoMinutes' => 2,
            'everyThreeMinutes' => 3,
            'everyFourMinutes' => 4,
            'everyTenMinutes' => 10,
            'everyFifteenMinutes' => 15,
            'everyThirtyMinutes' => 30,
            'hourly' => 60,
            default => 5,
        };
    }

    /**
     * Apply the configured cadence to a scheduled event, falling back to the given
     * default token when the configured one is not supported.
     *
     * @throws InvalidArgumentException when the fallback itself is not a supported token
     */
    public static function apply(Event $event, string $cadence, string $fallback): void
    {
        $token = self::supports($cadence) ? $cadence : $fallback;

        match ($token) {
            'everyMinute' => $event->everyMinute(),
            'everyTwoMinutes' => $event->everyTwoMinutes(),
            'everyThreeMinutes' => $event->everyThreeMinutes(),
            'everyFourMinutes' => $event->everyFourMinutes(),
            'everyFiveMinutes' => $event->everyFiveMinutes(),
            'everyTenMinutes' => $event->everyTenMinutes(),
            'everyFifteenMinutes' => $event->everyFifteenMinutes(),
            'everyThirtyMinutes' => $event->everyThirtyMinutes(),
            'hourly' => $event->hourly(),
            default => throw new InvalidArgumentException(
                "[{$token}] is not a schedule cadence this package supports. Use one of: "
                .implode(', ', self::TOKENS).'.'
            ),
        };
    }

    /**
     * Whether the token names a cadence this package can schedule.
     */
    public static function supports(string $cadence): bool
    {
        return in_array($cadence, self::TOKENS, true);
    }
}
