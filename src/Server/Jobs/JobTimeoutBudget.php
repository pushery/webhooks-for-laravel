<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server\Jobs;

use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Support\Settings;

/**
 * Whether the delivery job can outlive the window its queue connection reserves it for.
 *
 * The failure is a duplicate delivery rather than a delay. A queue connection makes a reserved job
 * available again after `retry_after` seconds, on the assumption that a worker holding it longer
 * than that has died. The worker itself gives up at the job's `timeout`. So if the timeout reaches
 * `retry_after`, a second worker picks the job up while the first is still inside its HTTP call,
 * and the endpoint receives the same webhook twice. Laravel's own documentation states the
 * relationship the other way round: `retry_after` must always exceed the timeout.
 *
 * {@see CallWebhookJob} derives its timeout from the HTTP budget — connect + total + headroom —
 * and bounds it from BELOW so a worker cannot kill a request mid-flight. Nothing bounded it from
 * above, and the shipped config invites the raise: a slow consumer is the documented reason to
 * increase `server.timeout`. Measured against the framework's default `retry_after` of 90:
 *
 *   connect  timeout   job timeout   retry_after   result
 *   3        5         30            90            ok
 *   5        60        75            90            ok
 *   10       120       140           90            delivered twice
 *   3        300       313           90            delivered twice
 *
 * So the boundary sits at a `server.timeout` an operator would reach by raising it once, and
 * nothing on either side says a word. The package cannot fix this itself — `queue.connections.*`
 * belongs to the host, and lowering its own timeout would reintroduce the mid-flight kill this
 * arithmetic exists to prevent — so it reports, in the one place a host runs to be told the
 * install is sound.
 *
 * @internal
 */
final class JobTimeoutBudget
{
    /**
     * The fault to report, or null when there is nothing to say.
     *
     * Null covers three distinct states, and lumping them together is deliberate: a connection
     * with no `retry_after` (sync, or a driver that reserves differently), a connection that is
     * not configured at all, and a budget that fits. None of them is something an operator can
     * act on, and a preflight that speaks when there is no action is one people stop reading.
     */
    public static function fault(): ?string
    {
        $connection = self::connection();
        $retryAfter = Config::get("queue.connections.{$connection}.retry_after");

        if (! is_int($retryAfter) || $retryAfter <= 0) {
            return null;
        }

        $settings = new Settings;

        // The same arithmetic CallWebhookJob applies, read from the same settings rather than
        // repeated as numbers — a headroom that changed in one place and not the other would
        // make this check quietly wrong in the direction that reports nothing.
        $timeout = max(
            CallWebhookJob::MINIMUM_TIMEOUT,
            $settings->connectTimeout() + $settings->timeout() + CallWebhookJob::TIMEOUT_HEADROOM,
        );

        if ($timeout < $retryAfter) {
            return null;
        }

        return sprintf(
            'A delivery job may run for %ds (connect %ds + timeout %ds + headroom %ds, floored at '
            .'%ds), and the [%s] queue connection releases a reserved job after %ds. A second '
            .'worker therefore picks the delivery up while the first is still sending it, and the '
            .'endpoint receives the same webhook twice. Raise queue.connections.%s.retry_after '
            .'above %ds, or lower webhooks.server.timeout.',
            $timeout,
            $settings->connectTimeout(),
            $settings->timeout(),
            CallWebhookJob::TIMEOUT_HEADROOM,
            CallWebhookJob::MINIMUM_TIMEOUT,
            $connection,
            $retryAfter,
            $connection,
            $timeout,
        );
    }

    /**
     * The queue connection outbound deliveries are pushed onto.
     *
     * Read null-safe rather than through the typed getter, for the reason PreflightCommand states
     * beside its own read of this key: `webhooks.server.connection` is `env()` with no default, so
     * on any host that has not set it the key is present and null — and a typed getter throws on a
     * present null, turning a preflight into a crash on the default installation.
     */
    private static function connection(): string
    {
        $configured = Config::get('webhooks.server.connection');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $default = Config::get('queue.default');

        // Two statements rather than a ternary, for the coverage reason this package states
        // wherever a constant fallback appears: pcov credits a one-line expression to every line
        // it spans, so the fallback would read as covered the first time the other arm ran — and
        // this fallback is what decides whether the check runs at all on a host with no queue
        // configured.
        if (is_string($default) && $default !== '') {
            return $default;
        }

        return 'sync';
    }
}
