<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Date;

/**
 * Parses an HTTP `Retry-After` header into a whole number of seconds to wait. Per
 * RFC 9110 the value is either a non-negative delta in seconds (e.g. `120`) or an
 * absolute HTTP-date (e.g. `Wed, 21 Oct 2015 07:28:00 GMT`); an HTTP-date is
 * resolved relative to now and floored at zero so a past date never yields a
 * negative wait. Anything unparseable, or a missing header, is null — the caller
 * then falls back to its normal jittered backoff.
 *
 * @internal
 */
final class RetryAfter
{
    public static function parse(?string $header): ?int
    {
        if ($header === null) {
            return null;
        }

        $value = trim($header);

        // An empty value would fail both patterns below as well (`preg_match` answers 0 for
        // each), so the alphabetic check would refuse it and the method return null anyway.
        //
        // It stays because "the header was present but blank" is a distinct thing to have said
        // out loud, and because it keeps the two regexes describing only the shapes they are
        // about rather than doubling as an emptiness test.
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        // The only other valid form is an HTTP-date, and it is matched against the three
        // formats RFC 9110 §5.6.7 permits rather than handed to strtotime.
        //
        // strtotime would answer far more than that. It coerces numeric junk ("-5", "1e3") into a
        // timestamp, and its relative-format vocabulary is enormous: "+1 hour", "tomorrow",
        // "next week" and "midnight" all parse. None of those is an HTTP-date, and the docblock
        // above says in as many words that anything unparseable is null. Handed to strtotime, a
        // receiver — or a proxy rewriting headers — could steer the delivery schedule with a
        // value the package promises to ignore, and "midnight" would steer it to zero.
        //
        // Matching the three permitted shapes closes both directions at once, with no "looks like
        // a date" pre-check in front of it: a real date parser does not need one.
        $timestamp = self::httpDate($value);

        if ($timestamp === null) {
            return null;
        }

        return max(0, $timestamp - Date::now()->getTimestamp());
    }

    /**
     * The instant an HTTP-date names, or null when the value is not one.
     *
     * RFC 9110 §5.6.7 permits exactly three spellings: the preferred IMF-fixdate, and two
     * obsolete forms a recipient must still accept. All three are anchored and parsed with an
     * explicit format, so nothing else can be coerced into a wait.
     */
    private static function httpDate(string $value): ?int
    {
        // GMT is literal in the first two: RFC 9110 requires that exact zone token, so a value
        // carrying any other is not an HTTP-date and must not be read as one.
        $formats = [
            'D, d M Y H:i:s \G\M\T',   // IMF-fixdate — the one a sender should use
            'l, d-M-y H:i:s \G\M\T',   // RFC 850, obsolete
            'D M j H:i:s Y',             // asctime, obsolete
        ];

        foreach ($formats as $format) {
            // The `!` resets every field the format does not name, and none of these three
            // leaves one unnamed -- all of year, month, day, hour, minute and second are read
            // from the value. So it changes no result here; it stays because a format string
            // without it silently borrows from "now" the moment somebody adds a shorter one.
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('GMT'));

            // `createFromFormat` succeeds on a value with trailing junk and on impossible dates
            // it rolls over (32 January becomes 1 February), so the warnings are what decide it.
            $errors = DateTimeImmutable::getLastErrors();

            // Two halves of this line cannot be told apart from their alternatives, and one
            // fact explains both: an ERROR never coexists with a successful parse, in any of the
            // three formats -- a rollover, an out-of-range time, trailing junk or a wrong zone
            // that produces an error returns false as well. So the instanceof already implies
            // error_count is zero, which leaves
            // the sum and the difference reading the same, and leaves the type check unable to
            // let a false through even if it were removed.
            //
            // Both stay: the instanceof is what the declared `static|false` return asks for, and
            // the sum is what the sentence above says -- warnings and errors both disqualify.
            if ($parsed instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] + $errors['error_count']) === 0)) {
                return $parsed->getTimestamp();
            }
        }

        return null;
    }
}
