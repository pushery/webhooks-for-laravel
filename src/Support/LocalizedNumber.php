<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

/**
 * Number formatting that follows the reader's language, and needs no PHP extension to do it.
 *
 * `Illuminate\Support\Number` is the obvious answer and cannot be used here. Every locale-aware
 * method on that class — format, parse, spell, ordinal, spellOrdinal, percentage, currency, and
 * fileSize/abbreviate/forHumans through format — opens with `ensureIntlExtensionIsInstalled()` and
 * throws a RuntimeException when `ext-intl` is absent. Neither this package nor the framework
 * requires that extension. Adopting it to fix wrong separators would have replaced a misread number
 * with a fatal error, on twenty-six screen surfaces instead of the one that already carried the
 * call.
 *
 * That is not hypothetical: `PruneOrphanedPayloadsCommand` shipped `Number::fileSize()`, so a run
 * of it failed on such a host — after the deletion, before it could say what it had freed. The forbidden method list is derived from the framework rather than repeated.
 *
 * The separators come from `lang/<locale>/formats.php`, beside the date patterns, for the same
 * reason those are translatable: they are properties of the language and not of a screen. de,
 * es, it, nl and pt swap en's two characters, and fr groups thousands with a narrow no-break
 * space (U+202F) — so a German operator reading an English-formatted `1,234.5 ms` misreads it by
 * a factor of a thousand rather than merely finding it ugly.
 *
 * Internal despite being called by name from shipped Blade, the same way {@see UiAssets} is. A
 * published view carries the fully-qualified call, so a host who publishes one takes on that
 * dependency — but the view is the package's, moves with it, and is republished on upgrade. The
 * alternative is a public API surface that grows every time a view needs a helper.
 *
 * @internal
 */
final class LocalizedNumber
{
    /**
     * The byte-size units, smallest first. Ordinary binary steps, the same ladder the
     * framework's own fileSize walks. The rounding is not the framework's: number_format()
     * rounds an exact half away from zero, where the framework's NumberFormatter rounds it to
     * even, so 2560 bytes read "3 KB" here and "2 KB" there.
     *
     * @var list<string>
     */
    private const array UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

    /**
     * A number in the reader's notation.
     */
    public static function format(int|float $value, int $precision = 0): string
    {
        // All three casts answer the type checker rather than the values. `__()` is declared
        // `array|string|null` because a translation file may return an array, and an int
        // argument widens on its own. Dropping any of them leaves every output unchanged.
        return number_format(
            (float) $value,
            $precision,
            (string) __('webhooks::formats.decimal'),
            (string) __('webhooks::formats.thousands'),
        );
    }

    /**
     * A byte count in the reader's notation, in the largest unit that leaves it above 0.9.
     */
    public static function fileSize(int|float $bytes, int $precision = 0): string
    {
        // As in format(): the cast reads the union once, here, so the rest of the method is
        // about the ladder. It changes no output either -- abs(), the division and format()
        // all take an int just as well.
        $value = (float) $bytes;
        $unit = 0;
        $last = count(self::UNITS) - 1;

        while (abs($value) / 1024 > 0.9 && $unit < $last) {
            $value /= 1024;
            $unit++;
        }

        return self::format($value, $precision).' '.self::UNITS[$unit];
    }
}
