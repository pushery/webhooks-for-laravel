<?php

declare(strict_types=1);

namespace Webhooks\Server\Delivery;

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

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        // The only other valid form is an HTTP-date, which always carries an
        // alphabetic month/day name; reject numeric junk ("-5", "1e3") that
        // strtotime would otherwise coerce into a spurious timestamp.
        if (preg_match('/[A-Za-z]/', $value) !== 1) {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - Date::now()->getTimestamp());
    }
}
