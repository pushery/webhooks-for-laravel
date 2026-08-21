<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Dashboard;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;

/**
 * How much of a delivery's stored body the acting user may see.
 *
 * The dashboard's route gate (`view-webhook-dashboard`) answers "may you read the delivery
 * LOG" — the status, the timing, the endpoint. The body is a different question. In a real
 * integration `webhook_deliveries.payload` is the business record itself: the order, the
 * customer, the shipping address. Letting one ability answer both means "may see that a
 * delivery failed" silently implies "may see whose order it was", and that is not a
 * permission level, it is the absence of one.
 *
 * So the body carries its OWN ability, and it fails CLOSED. That direction is deliberate and
 * it is the same reasoning {@see DashboardScope::authorizeAllTenants()} spells out: the
 * dashboard ability is one a per-tenant dashboard necessarily grants broadly, because every
 * customer needs it to see their own deliveries. Defaulting the body open would therefore
 * hand it to everyone the broad ability admits — silently, with nothing failing a test.
 *
 * A host that genuinely wants every dashboard user to read every body says so explicitly —
 * `Gate::define('view-webhook-payload', fn () => true)` — which is greppable and reviewable,
 * unlike an absence.
 *
 * Denial does not mean a blank space. The default fallback REDACTS: the structure of the body
 * survives, its values do not. That is a deliberate design choice rather than a softer one —
 * for debugging, the shape of a body is almost always the useful part, and a drawer that
 * simply stops after its heading reads as a defect, which is how a guard gets "repaired" out
 * of existence by the next person to look at it.
 *
 * @internal Engine internals, deliberately. A host's extension points here are the ABILITY and
 * the two config keys, not this class — so the drawer view resolves the decision through the
 * component rather than naming this class, and a published view keeps working if the mechanism
 * behind it is reshaped inside the 1.x line.
 */
final class PayloadVisibility
{
    /**
     * The whole body, values included.
     */
    public const string MODE_FULL = 'full';

    /**
     * Structure preserved, every scalar leaf replaced by its type.
     */
    public const string MODE_REDACTED = 'redacted';

    /**
     * No body at all, only the notice explaining why.
     */
    public const string MODE_HIDDEN = 'hidden';

    /**
     * What the acting user gets for a delivery body.
     *
     * Full only when the configured ability EXISTS and passes. A blank ability name, an
     * ability the host never defined, and a user who fails it all land on the configured
     * fallback — never on the full body.
     */
    public static function current(): string
    {
        $ability = Config::string('webhooks.dashboard.payload.ability', 'view-webhook-payload');

        if ($ability !== '' && Gate::has($ability) && Gate::allows($ability)) {
            return self::MODE_FULL;
        }

        return self::fallback();
    }

    /**
     * What a denied read falls back to. Anything other than the two supported tokens is
     * treated as the stricter one: a typo in a security setting must not be the permissive
     * reading, because a misspelled 'redacted' would otherwise open the body it was meant
     * to close.
     */
    public static function fallback(): string
    {
        return Config::string('webhooks.dashboard.payload.denied', self::MODE_REDACTED) === self::MODE_REDACTED
            ? self::MODE_REDACTED
            : self::MODE_HIDDEN;
    }

    /**
     * Replace every scalar leaf with its type while keeping the structure intact.
     *
     * Keys are kept — they are the shape, and the shape is the point. Null is kept as null
     * rather than labeled: a null carries no value to leak, and collapsing it into a marker
     * would hide the one distinction an operator most often needs, "the field was there but
     * empty" versus "the field was never sent".
     */
    public static function redact(mixed $payload): mixed
    {
        if (is_array($payload)) {
            $redacted = [];

            foreach ($payload as $key => $value) {
                $redacted[$key] = self::redact($value);
            }

            return $redacted;
        }

        return match (true) {
            $payload === null => null,
            is_bool($payload) => '[bool]',
            is_int($payload) => '[int]',
            is_float($payload) => '[float]',
            is_string($payload) => '[string]',
            default => '[redacted]',
        };
    }
}
