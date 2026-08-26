<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard;

use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Pushery\Webhooks\Support\UiTheme;
use Throwable;

/**
 * The zone the dashboard RENDERS its timestamps in — which is a different question from the
 * zone they are stored or read in, and until now the package only had an answer for the second.
 *
 * Every dashboard timestamp already carries the application zone: the delivery model uses
 * HasZonedTimestamps, which shifts a read into `app.timezone`, and the absolute format ends in
 * `z` so the reader can see which clock they are being shown. That is correct, and labelling it
 * was already an improvement over a bare wall-clock time.
 *
 * ⚠️ WHAT WAS MISSING IS A SEAM, NOT A ZONE. `app.timezone` is ONE process-wide setting. In a
 * multi-tenant back-office it is `UTC` — the right choice for storage and the wrong one for
 * display — while the operator reading the delivery log sits in Europe/Berlin and the next
 * tenant sits somewhere else. There is no single correct value an application could set, so
 * labelling the offset only tells the reader they have to do the arithmetic themselves, on the
 * one surface where they are comparing timestamps against their own records.
 *
 * Unset, this changes nothing at all: the application zone, exactly as before.
 *
 * Two shapes, because the two cases are genuinely different:
 *
 *   'timezone' => 'Europe/Berlin'                 // one operator, or one tenant
 *   'timezone' => TenantDisplayZone::class        // resolved per render
 *
 * The class form is the same mechanism as {@see PayloadVisibility}:
 * the host decides, the package asks. It must implement {@see DashboardTimezoneResolver}.
 *
 * A MISTYPED ZONE FALLS BACK RATHER THAN THROWING, and that follows the rule this package
 * already applies to presentation settings ({@see UiTheme}): a typo
 * in a display setting must never take a dashboard down. The fallback is also self-revealing
 * here in a way most fallbacks are not — the absolute format ends in `z`, so a reader who
 * expected their own zone sees the application's named beside the value rather than a plausible
 * wrong number.
 *
 * A RESOLVER CLASS THAT IS NOT ONE DOES throw, and the asymmetry is deliberate: a bad zone
 * string is data, and data is mistyped; a class that does not implement the contract is wiring,
 * and wiring is wrong rather than mistyped. It is the same split every other resolver seam in
 * this package makes.
 *
 * @internal
 */
final class DashboardTimezone
{
    /**
     * The configured display zone, or null to leave the value in the application zone.
     */
    public static function current(): ?string
    {
        $configured = Config::get('webhooks.dashboard.timezone');

        // The `=== ''` half is an EQUIVALENT mutant and is reported every run: measured, an
        // empty zone reaches `validated()`, `new DateTimeZone('')` throws
        // DateInvalidTimeZoneException, and the catch answers null — the same answer, three
        // calls later.
        //
        // It stays because an unset env var arrives here as '' rather than as null, which
        // makes this the ordinary shape of "no zone configured" and not an edge case.
        if (! is_string($configured) || $configured === '') {
            return null;
        }

        if (class_exists($configured)) {
            return self::fromResolver($configured);
        }

        return self::validated($configured);
    }

    /**
     * Move a timestamp into the display zone, or hand it back untouched when there is none.
     *
     * Every dashboard surface that renders a timestamp goes through here — not just the drawer.
     * Two columns of one screen showing two different clocks would be worse than one clock that
     * is not the reader's, because the reader has no way to know it is happening.
     */
    public static function apply(CarbonInterface $at): CarbonInterface
    {
        $zone = self::current();

        return $zone === null ? $at : $at->setTimezone($zone);
    }

    private static function fromResolver(string $class): ?string
    {
        $resolver = app()->make($class);

        if (! $resolver instanceof DashboardTimezoneResolver) {
            throw new InvalidArgumentException(
                "The configured webhooks.dashboard.timezone [{$class}] must implement "
                .DashboardTimezoneResolver::class.'. A class name there is resolved per render '
                .'so a multi-tenant host can answer with the reading tenant\'s own zone; pass a '
                .'plain zone identifier instead for a single fixed zone.'
            );
        }

        $zone = $resolver->timezone();

        return $zone === null ? null : self::validated($zone);
    }

    /**
     * The zone if the runtime knows it, null otherwise.
     *
     * DateTimeZone's constructor is the authority rather than a list of our own: the identifier
     * database ships with PHP and moves with it, so any list here would be wrong the first time
     * a zone is added or renamed.
     */
    private static function validated(string $zone): ?string
    {
        try {
            new DateTimeZone($zone);
        } catch (Throwable) {
            return null;
        }

        return $zone;
    }
}
