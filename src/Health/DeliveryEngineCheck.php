<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Health;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Database\Dialect\Sql\ConditionalCount;
use Pushery\Webhooks\Enums\DeliveryStatus;
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
 * The read is bounded below on `created_at`, so on PostgreSQL only the monthly partitions the
 * window reaches are scanned. Of the deliveries the result carries counts, never a payload, a URL or
 * an error text. The one sentence in it the package did not write is the remedy a host names.
 */
final class DeliveryEngineCheck extends Check
{
    private int $windowHours = 24;

    private float $failAbovePercent = 50.0;

    private ?float $warnAbovePercent = null;

    private int $minimumDeliveries = 1;

    private bool $warnOnDisabledEndpoints = true;

    private ?string $disabledEndpointRemedy = null;

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

        $settled = $counts['succeeded'] + $counts['failed'] + $counts['exhausted'];
        $rate = $settled > 0 ? round(($counts['failed'] + $counts['exhausted']) / $settled * 100, 1) : null;

        // Laravel Health declares its meta as strings, integers and booleans, and a history store
        // may rely on that, so the rate goes in as its written form and is absent without a sample.
        $result->meta([
            'window_hours' => $this->windowHours,
            ...$counts,
            ...($rate === null ? [] : ['failure_rate_percent' => $this->machinePercent($rate)]),
            'disabled_endpoints' => $disabled,
        ]);

        if ($rate !== null && $settled >= $this->minimumDeliveries) {
            $message = sprintf('%s%% of the %d deliveries in the last %d hours failed.', $this->percent($rate), $settled, $this->windowHours);

            if ($rate > $this->failAbovePercent) {
                return $result->shortSummary($this->percent($rate).'% failed')->failed($message);
            }

            if ($this->warnAbovePercent !== null && $rate > $this->warnAbovePercent) {
                return $result->shortSummary($this->percent($rate).'% failed')->warning($message);
            }
        }

        if ($this->warnOnDisabledEndpoints && $disabled > 0) {
            $message = $disabled === 1
                ? 'One webhook endpoint is switched off and receives nothing until it is enabled again.'
                : sprintf('%d webhook endpoints are switched off and receive nothing until they are enabled again.', $disabled);

            return $result->shortSummary($disabled === 1 ? '1 endpoint off' : $disabled.' endpoints off')->warning(
                $this->disabledEndpointRemedy === null ? $message : $message.' '.$this->disabledEndpointRemedy,
            );
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
