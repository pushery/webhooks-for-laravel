<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

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
 * ⚠️ NON-SCALARS ARE DROPPED, AND THAT IS DELIBERATE RATHER THAN CONVENIENT. `strval()` on a
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
     * @param  array<array-key, mixed>  $stored
     * @return list<string>
     */
    public static function fromStorage(array $stored): array
    {
        return array_values(array_map(strval(...), array_filter($stored, is_scalar(...))));
    }
}
