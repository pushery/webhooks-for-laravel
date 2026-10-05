<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Closure;
use Pushery\Webhooks\Database\Dialect\Dialect;
use Pushery\Webhooks\Models\WebhookSubscription;

/**
 * Turns whatever `webhook_subscriptions.event_types` actually holds into the list of strings
 * the two subscription forms declare it to be.
 *
 * The column is a JSON cast, so the shape is whatever was written into it — and the two forms
 * that read it declare `array<int, string>` and bind checkboxes against it. A row holding a
 * JSON OBJECT rather than a list, or a number rather than a name, therefore arrives with keys
 * and with the type JSON gave it, and the endpoint becomes impossible to save: `eventTypes.*`
 * refuses the non-string, and an operator who only wanted to fix the NAME is told about an
 * event type they never touched, with no control to remove it by — the form draws one checkbox
 * per catalog entry, and a stray value is in no catalog. The screen is dead for that row.
 *
 * `save()` already carries exactly this care for the allowlist: what the opened row holds stays
 * acceptable even once the catalog stops declaring it. This is the same care one method
 * earlier, at the load.
 *
 * Non-scalars are dropped, and that is deliberate rather than convenient. `strval()` on a
 * nested array does not fail — it emits an "Array to string conversion" warning and yields the
 * literal string `Array`, which would be written back as if it were an event type. A value that
 * cannot be a name cannot be deselected either, so keeping it would leave the endpoint exactly
 * as unsaveable as before. Nothing is rewritten by loading; the row only changes if the
 * operator saves it.
 *
 * @internal The seam a host reads is {@see WebhookSubscription::eventTypeNames()};
 *           this class is the implementation behind it and may move.
 */
final class EventTypeList
{
    /**
     * The longest event type the two forms register an endpoint for, in characters. It is the
     * width of the MySQL index over the column, which refuses a longer value outright, so a
     * name the forms accept stores on every supported engine.
     */
    public const int MAX_LENGTH = 255;

    /**
     * How many event types the two forms register one endpoint for while the application
     * declares no catalog. Without a catalog the forms offer no type to select, so only a
     * request written by hand reaches this.
     */
    public const int MAX_UNCATALOGED = 100;

    /**
     * How many bytes all of one endpoint's event types may take together on MySQL. The
     * multi-valued index over the column refuses a row whose values run past about 5,344 bytes
     * in total, error 3906 on MySQL 8.4, and a form that let such a list through ended in a
     * server error. The budget stays under that, and it applies on MySQL alone: PostgreSQL has
     * no such bound, and the forms apply none there.
     */
    public const int MYSQL_MAX_TOTAL_BYTES = 5000;

    /**
     * A scalar where the list belongs, a single name written without its brackets, reads as a
     * list of that one value, the same as it would inside a list. Anything else that is not an
     * array, a JSON null, reads as no types. Both are what the column's cast hands back for a
     * row a host wrote outside the forms, and a type error on either would take down the whole
     * list of endpoints, not only the row.
     *
     * @return list<string>
     */
    public static function fromStorage(mixed $stored): array
    {
        if (is_scalar($stored)) {
            $stored = [$stored];
        }

        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_map(strval(...), array_filter($stored, is_scalar(...))));
    }

    /**
     * How many event types one save may register an endpoint for.
     *
     * With a catalog it is the number of types the form accepts, so every type it offers can
     * be selected at once and only a list padded with repeats goes past it. Without one it is
     * {@see self::MAX_UNCATALOGED}. Either way an endpoint that already holds more keeps them
     * through an edit: a save is not refused over types nobody touched.
     *
     * @param  list<string>|null  $accepted  what the form accepts, null without a catalog
     * @param  list<string>  $stored  what the endpoint holds now, empty for a new one
     */
    public static function maxCount(?array $accepted, array $stored): int
    {
        return max($accepted === null ? self::MAX_UNCATALOGED : count($accepted), count($stored));
    }

    /**
     * The bytes a list of event types takes in the MySQL index: the UTF-8 length of every value,
     * a repeated one counted each time.
     *
     * @param  array<mixed>  $types
     */
    public static function totalBytes(array $types): int
    {
        $bytes = 0;

        foreach ($types as $type) {
            if (is_string($type)) {
                $bytes += strlen($type);
            }
        }

        return $bytes;
    }

    /**
     * The most bytes of event types one save may carry on MySQL. Like {@see self::maxCount()}, it never
     * falls below what the endpoint already holds: a host may store more in code than the forms
     * take, and a row MySQL stored fits its index, so keeping it through a rename cannot reach the
     * server's error.
     *
     * @param  list<string>  $stored
     */
    public static function maxBytes(array $stored): int
    {
        return max(self::MYSQL_MAX_TOTAL_BYTES, self::totalBytes($stored));
    }

    /**
     * The rule both forms put on `eventTypes`: on MySQL, the list takes at most `$maxBytes`.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function fitsTheMySqlIndex(int $maxBytes, string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($maxBytes, $message): void {
            if (is_array($value)
                && WebhookConnection::dialect() === Dialect::MySql
                && self::totalBytes($value) > $maxBytes
            ) {
                $fail($message);
            }
        };
    }
}
