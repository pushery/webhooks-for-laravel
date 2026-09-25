<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Health;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Database\Dialect\Sql\ConditionalCount;
use Pushery\Webhooks\Enums\DeliveryStatus;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Health\HealthStatus;
use Pushery\Webhooks\Support\LocalizedNumber;
use Pushery\Webhooks\Support\Timestamp;
use Pushery\Webhooks\Support\WebhookConnection;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * A check for spatie/laravel-health that reports on the delivery engine as a whole: how many of
 * the deliveries in a recent window failed, and whether any endpoint is switched off.
 *
 * Register it beside the other checks of your application:
 *
 *     Health::checks([
 *         DeliveryEngineCheck::new(),
 *     ]);
 *
 * The rate is the one the endpoint health score uses: failures over the deliveries that reached an
 * attempted outcome. A pending delivery has no outcome yet and is left out. A refused one was never
 * sent — its endpoint was switched off while it waited in the queue — so it says nothing about any
 * destination and is left out too, which is the same call the circuit breaker makes. A delivery
 * between two attempts counts as failed until it resolves, which is where the score puts it.
 *
 * A rate cannot see the engine stop. Without a worker there are no outcomes, so there are no
 * failures either, and the rate of a dead engine reads like a quiet night. Two limits say so
 * instead, both off until a host sets them: a delivery that has waited too long to be sent at all,
 * and a delivery to a live endpoint whose next attempt never came.
 *
 * Every read is bounded below on `created_at`, so on PostgreSQL only the monthly partitions it
 * reaches are scanned. Of the deliveries the result carries counts, never a payload, a URL or an
 * error text. The one sentence in it the package did not write is the remedy a host names.
 */
final class DeliveryEngineCheck extends Check
{
    private int $windowHours = 24;

    private float $failAbovePercent = 50.0;

    private ?float $warnAbovePercent = null;

    private int $minimumDeliveries = 1;

    private bool $warnOnDisabledEndpoints = true;

    private bool $warnOnlyAboutBreakerDisabledEndpoints = false;

    private ?string $disabledEndpointRemedy = null;

    private ?int $pendingLimitMinutes = null;

    private ?int $retryLimitMinutes = null;

    private ?int $stallLookBackHours = null;

    private bool $warnOnFailingEndpoints = false;

    /**
     * How far back the check looks, in hours. 24 by default.
     */
    public function window(int $hours): self
    {
        $this->windowHours = max(1, $hours);

        return $this;
    }

    /**
     * Fail when more than this share of the window's deliveries failed, in percent. 50 by default.
     */
    public function failWhenFailureRateAbove(float $percent): self
    {
        $this->failAbovePercent = $percent;

        return $this;
    }

    /**
     * Warn when more than this share of the window's deliveries failed, in percent. Off by default.
     */
    public function warnWhenFailureRateAbove(float $percent): self
    {
        $this->warnAbovePercent = $percent;

        return $this;
    }

    /**
     * Judge the rate only once the window holds at least this many deliveries with an outcome, so
     * one failed delivery on a quiet night is not a 100 % failure rate. 1 by default.
     */
    public function minimumDeliveries(int $count): self
    {
        $this->minimumDeliveries = max(1, $count);

        return $this;
    }

    /**
     * Warn while an endpoint is switched off, by the circuit breaker or by hand. On by default.
     */
    public function warnWhenEndpointsAreDisabled(bool $warn = true): self
    {
        $this->warnOnDisabledEndpoints = $warn;

        return $this;
    }

    /**
     * A sentence that tells the reader of the disabled-endpoint warning where an endpoint is switched
     * back on, appended to the warning. Only the application knows its own screens, so there is no
     * default, and the count and the state in the message stay the package's. Off by default.
     */
    public function remedyForDisabledEndpoints(string $sentence): self
    {
        $sentence = trim($sentence);
        $this->disabledEndpointRemedy = $sentence === '' ? null : $sentence;

        return $this;
    }

    /**
     * Warn only about endpoints the circuit breaker switched off. An endpoint switched off by hand
     * is somebody's decision rather than news, and the warning then leaves it out. Both kinds stay
     * in the count the result carries.
     */
    public function warnOnlyAboutEndpointsTheBreakerDisabled(): self
    {
        $this->warnOnDisabledEndpoints = true;
        $this->warnOnlyAboutBreakerDisabledEndpoints = true;

        return $this;
    }

    /**
     * Fail when a delivery has waited longer than this to be sent at all, in minutes: nothing is
     * taking work off the queue. Off by default.
     *
     * A delivery to an endpoint over its outbound rate limit waits as pending until its window
     * opens, so choose a limit longer than the longest such wait you expect.
     */
    public function failWhenADeliveryIsPendingLongerThan(int $minutes): self
    {
        $this->pendingLimitMinutes = max(1, $minutes);

        return $this;
    }

    /**
     * Fail when a delivery to an endpoint that is switched on has waited longer than this for its
     * next attempt, in minutes: the attempt never ran, which is what a worker that stopped between
     * two attempts leaves behind. Off by default.
     *
     * The age is counted from the delivery's creation, so choose a limit beyond the whole retry
     * schedule, every backoff and every honored `Retry-After` included. A delivery that is only
     * between two attempts must not reach it.
     */
    public function failWhenARetryIsOverdueAfter(int $minutes): self
    {
        $this->retryLimitMinutes = max(1, $minutes);

        return $this;
    }

    /**
     * How far back the two limits above look for a delivery that is still waiting, in hours, counted
     * back from the limit. One window by default.
     *
     * A worker that stopped leaves its last deliveries waiting, and once no new ones arrive they age
     * out of a look-back of one window: the check turns green over a jam nobody cleared. A longer
     * look-back keeps reporting it, at the cost of reading further back into the delivery log.
     */
    public function lookForStallsBack(int $hours): self
    {
        $this->stallLookBackHours = max(1, $hours);

        return $this;
    }

    /**
     * Warn while an endpoint that is switched on has a failing health score. Off by default.
     *
     * One endpoint that fails while the others deliver keeps the rate of the whole engine low, so the
     * rate does not see it, and the circuit breaker, where it is on, switches it off without a warning
     * beforehand. The score is written by endpoint health scoring, so this needs that switched on.
     */
    public function warnWhileAnEndpointIsFailing(): self
    {
        $this->warnOnFailingEndpoints = true;

        return $this;
    }

    public function run(): Result
    {
        $result = Result::make();

        if (! Config::boolean('webhooks.platform.enabled', true)) {
            // A check with no delivery log to read is a misconfiguration, and it says so rather
            // than reporting a healthy engine it never looked at.
            return $result->shortSummary('Platform layer off')->failed(
                'The Platform layer is off (webhooks.platform.enabled), so there is no delivery log '
                .'to read. Enable the layer, or remove this check.',
            );
        }

        $counts = $this->deliveryCounts();
        $disabled = $this->disabledEndpoints();
        $stalledPending = $this->pendingLimitMinutes === null ? null : $this->stalledPending($this->pendingLimitMinutes);
        $overdueRetries = $this->retryLimitMinutes === null ? null : $this->overdueRetries($this->retryLimitMinutes);
        $breakerDisabled = $this->warnOnlyAboutBreakerDisabledEndpoints ? $this->breakerDisabledEndpoints() : null;
        $failing = $this->warnOnFailingEndpoints ? $this->failingEndpoints() : null;

        $settled = $counts['succeeded'] + $counts['failed'] + $counts['exhausted'];
        $rate = $settled > 0 ? round(($counts['failed'] + $counts['exhausted']) / $settled * 100, 1) : null;

        // Laravel Health declares its meta as strings, integers and booleans, and a history store
        // may rely on that, so the rate goes in as its written form and is absent without a sample.
        $result->meta([
            'window_hours' => $this->windowHours,
            ...$counts,
            ...($rate === null ? [] : ['failure_rate_percent' => $this->machinePercent($rate)]),
            'disabled_endpoints' => $disabled,
            // Present only where a host asked for the measurement, so a check configured as before
            // keeps the meta it had.
            ...($breakerDisabled === null ? [] : ['breaker_disabled_endpoints' => $breakerDisabled]),
            ...($stalledPending === null ? [] : ['stalled_pending' => $stalledPending]),
            ...($overdueRetries === null ? [] : ['overdue_retries' => $overdueRetries]),
            ...($failing === null ? [] : ['failing_endpoints' => $failing]),
        ]);

        // A stopped engine outranks a failure rate: it produces no outcomes, so no rate reports it.
        if ($stalledPending !== null && $stalledPending > 0) {
            $message = $stalledPending === 1
                ? sprintf('One webhook delivery has waited more than %d minutes to be sent.', $this->pendingLimitMinutes)
                : sprintf('%d webhook deliveries have waited more than %d minutes to be sent.', $stalledPending, $this->pendingLimitMinutes);

            return $result->shortSummary('Queue stalled')->failed(
                $message.' Check that a worker is taking jobs off the queue the webhooks are sent from.',
            );
        }

        if ($overdueRetries !== null && $overdueRetries > 0) {
            $message = $overdueRetries === 1
                ? sprintf('One webhook delivery has waited more than %d minutes for its next attempt.', $this->retryLimitMinutes)
                : sprintf('%d webhook deliveries have waited more than %d minutes for their next attempt.', $overdueRetries, $this->retryLimitMinutes);

            return $result->shortSummary('Retries stalled')->failed(
                $message.' Check that a worker is taking jobs off the queue, then read the failed jobs for one that stops it.',
            );
        }

        if ($rate !== null && $settled >= $this->minimumDeliveries) {
            $message = sprintf('%s%% of the %d deliveries in the last %d hours failed.', $this->percent($rate), $settled, $this->windowHours);

            if ($rate > $this->failAbovePercent) {
                return $result->shortSummary($this->percent($rate).'% failed')->failed($message);
            }

            if ($this->warnAbovePercent !== null && $rate > $this->warnAbovePercent) {
                return $result->shortSummary($this->percent($rate).'% failed')->warning($message);
            }
        }

        if ($breakerDisabled !== null) {
            if ($breakerDisabled > 0) {
                $message = $breakerDisabled === 1
                    ? 'The circuit breaker switched one webhook endpoint off, and it receives nothing until it is enabled again.'
                    : sprintf('The circuit breaker switched %d webhook endpoints off, and they receive nothing until they are enabled again.', $breakerDisabled);

                return $result->shortSummary($breakerDisabled === 1 ? '1 endpoint off' : $breakerDisabled.' endpoints off')->warning(
                    $this->disabledEndpointRemedy === null ? $message : $message.' '.$this->disabledEndpointRemedy,
                );
            }
        } elseif ($this->warnOnDisabledEndpoints && $disabled > 0) {
            $message = $disabled === 1
                ? 'One webhook endpoint is switched off and receives nothing until it is enabled again.'
                : sprintf('%d webhook endpoints are switched off and receive nothing until they are enabled again.', $disabled);

            return $result->shortSummary($disabled === 1 ? '1 endpoint off' : $disabled.' endpoints off')->warning(
                $this->disabledEndpointRemedy === null ? $message : $message.' '.$this->disabledEndpointRemedy,
            );
        }

        // After the switched-off endpoints: one that is off already receives nothing, one that is
        // failing still receives its deliveries.
        if ($failing !== null && $failing > 0) {
            $message = $failing === 1
                ? 'One webhook endpoint that is switched on has a failing health score.'
                : sprintf('%d webhook endpoints that are switched on have a failing health score.', $failing);

            return $result->shortSummary($failing === 1 ? '1 endpoint failing' : $failing.' endpoints failing')->warning($message);
        }

        return $result->shortSummary($rate === null ? 'No deliveries' : $this->percent($rate).'% failed')->ok();
    }

    /**
     * A rate for a reader, in the reader's notation: one decimal where there is one, none where it
     * would be a zero.
     */
    private function percent(float $rate): string
    {
        $precision = fmod($rate, 1.0) === 0.0 ? 0 : 1;

        return LocalizedNumber::format($rate, $precision);
    }

    /**
     * The same rate for the meta a history store keeps, in one notation whatever the reader's
     * locale: `%F` is the one float conversion that ignores the locale, so a comma never reaches a
     * value somebody parses.
     */
    private function machinePercent(float $rate): string
    {
        $written = sprintf('%.1F', $rate);

        if (str_ends_with($written, '.0')) {
            return substr($written, 0, -2);
        }

        return $written;
    }

    /**
     * @return array{succeeded: int, failed: int, exhausted: int, refused: int, pending: int}
     */
    private function deliveryCounts(): array
    {
        $since = Timestamp::forDialect(WebhookConnection::dialect(), now()->subHours($this->windowHours));

        $select = [];

        foreach ([DeliveryStatus::Succeeded, DeliveryStatus::Failed, DeliveryStatus::Exhausted, DeliveryStatus::Refused, DeliveryStatus::Pending] as $status) {
            $select[] = ConditionalCount::of("status = '{$status->value}'").' AS '.$status->value;
        }

        $row = (array) WebhookConnection::db()->selectOne(
            'SELECT '.implode(', ', $select).' FROM webhook_deliveries WHERE created_at >= ?',
            [$since],
        );

        return [
            'succeeded' => $this->countIn($row, 'succeeded'),
            'failed' => $this->countIn($row, 'failed'),
            'exhausted' => $this->countIn($row, 'exhausted'),
            'refused' => $this->countIn($row, 'refused'),
            'pending' => $this->countIn($row, 'pending'),
        ];
    }

    private function disabledEndpoints(): int
    {
        return WebhookConnection::db()->table('webhook_subscriptions')
            ->where(static fn (Builder $query): Builder => $query->where('is_active', false)->orWhereNotNull('disabled_at'))
            ->count();
    }

    /**
     * Deliveries still pending although they were created more than the limit ago.
     *
     * The read covers one window's worth of creation times, ending at the limit, so it stays bounded
     * on both sides whatever the limit is and can never be an empty range.
     */
    private function stalledPending(int $limitMinutes): int
    {
        [$from, $until] = $this->agedRange($limitMinutes);

        return WebhookConnection::db()->table('webhook_deliveries')
            ->where('status', DeliveryStatus::Pending->value)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until)
            ->count();
    }

    /**
     * Deliveries still waiting between two attempts although they were created more than the limit
     * ago, to an endpoint that is switched on. The retries of a switched-off endpoint stopped on
     * purpose, and the disabled-endpoint warning is the one that reports it.
     */
    private function overdueRetries(int $limitMinutes): int
    {
        [$from, $until] = $this->agedRange($limitMinutes);

        return WebhookConnection::db()->table('webhook_deliveries')
            ->join('webhook_subscriptions', 'webhook_subscriptions.id', '=', 'webhook_deliveries.subscription_id')
            ->where('webhook_deliveries.status', DeliveryStatus::Failed->value)
            ->where('webhook_deliveries.created_at', '>=', $from)
            ->where('webhook_deliveries.created_at', '<', $until)
            ->where('webhook_subscriptions.is_active', true)
            ->whereNull('webhook_subscriptions.disabled_at')
            ->count();
    }

    /**
     * The creation times a delivery older than the limit can have: one look-back's worth, ending at
     * the limit, each bound written for the engine that holds the table. The look-back is one window
     * unless a host asked for a longer one.
     *
     * @return array{string, string}
     */
    private function agedRange(int $limitMinutes): array
    {
        $dialect = WebhookConnection::dialect();
        $until = now()->subMinutes($limitMinutes);

        return [
            Timestamp::forDialect($dialect, $until->copy()->subHours($this->stallLookBackHours ?? $this->windowHours)),
            Timestamp::forDialect($dialect, $until),
        ];
    }

    /**
     * Endpoints the circuit breaker switched off, told apart from one switched off by hand by the
     * model's own rule rather than a second copy of it here. The set is the switched-off endpoints,
     * which is small by construction.
     */
    private function breakerDisabledEndpoints(): int
    {
        $rows = WebhookConnection::db()->table('webhook_subscriptions')
            ->where(static fn (Builder $query): Builder => $query->where('is_active', false)->orWhereNotNull('disabled_at'))
            ->get()
            ->all();

        return WebhookSubscription::model()::hydrate($rows)
            ->filter(static fn (WebhookSubscription $subscription): bool => $subscription->wasAutoDisabled())
            ->count();
    }

    /**
     * Endpoints that are switched on and whose cached health status is failing. Endpoint health
     * scoring writes the status, and an endpoint it never scored has none, so it is not counted.
     */
    private function failingEndpoints(): int
    {
        return WebhookConnection::db()->table('webhook_subscriptions')
            ->where('is_active', true)
            ->whereNull('disabled_at')
            ->where('health_status', HealthStatus::Failing->value)
            ->count();
    }

    /**
     * One count out of the aggregate row. PostgreSQL returns aggregates as strings, and a column
     * that is missing or not a number is read as none rather than guessed at.
     *
     * @param  array<mixed>  $row
     */
    private function countIn(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        if (! is_numeric($value)) {
            return 0;
        }

        return (int) $value;
    }
}
