<?php

declare(strict_types=1);

namespace Webhooks\Pulse;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * The internal-ops Pulse card: outbound webhook throughput, failure rate and latency
 * for the selected Pulse period, with a per-event-type breakdown. It reads the three
 * entry types the {@see WebhookDeliveryRecorder} writes and derives the failure rate as
 * failure-count over throughput-count. Registered only by the opt-in
 * {@see WebhookPulseServiceProvider}, so it never loads without Pulse and pulse.enabled.
 *
 * Aggregates are read straight from Pulse's bucket storage on each render rather than
 * cross-request cached: an internal single-view monitor does not need the per-viewer
 * cache the busier first-party cards use.
 */
#[Lazy]
final class WebhookDeliveryCard extends Card
{
    public function render(): Renderable
    {
        $runAt = CarbonImmutable::now('UTC')->toDateTimeString();
        $startedAt = hrtime(true);

        $throughput = (int) $this->total(WebhookDeliveryRecorder::THROUGHPUT, 'count');
        $failures = (int) $this->total(WebhookDeliveryRecorder::FAILURE, 'count');

        $events = $this->eventBreakdown();

        $time = (hrtime(true) - $startedAt) / 1_000_000;

        return View::make('webhooks::pulse.webhook-deliveries', [
            'time' => $time,
            'runAt' => $runAt,
            'throughput' => $throughput,
            'failures' => $failures,
            'failureRate' => $throughput > 0 ? round($failures / $throughput * 100, 1) : 0.0,
            'avgLatency' => $this->total(WebhookDeliveryRecorder::LATENCY, 'avg'),
            'maxLatency' => $this->total(WebhookDeliveryRecorder::LATENCY, 'max'),
            'events' => $events,
        ]);
    }

    /**
     * The per-event-type rows: throughput, failures, failure rate and avg/max latency,
     * joined from the three entry types by their shared event-type key. Pulse hands the
     * aggregates back as mixed rows, so each field is coerced explicitly.
     *
     * @return Collection<int, WebhookEventStat>
     */
    private function eventBreakdown(): Collection
    {
        $latency = $this->keyByEvent($this->aggregate(WebhookDeliveryRecorder::LATENCY, ['avg', 'max']));
        $failures = $this->keyByEvent($this->aggregate(WebhookDeliveryRecorder::FAILURE, ['count']));

        return $this->aggregate(WebhookDeliveryRecorder::THROUGHPUT, ['count'], 'count')
            ->map(function (mixed $row) use ($latency, $failures): WebhookEventStat {
                $data = (array) $row;
                $key = $this->asString($data['key'] ?? '');
                $sample = $latency[$key] ?? [];
                $total = $this->asInt($data['count'] ?? 0);
                $failed = $this->asInt($failures[$key]['count'] ?? 0);

                return new WebhookEventStat(
                    event: $key,
                    total: $total,
                    failed: $failed,
                    failureRate: $total > 0 ? round($failed / $total * 100, 1) : 0.0,
                    avg: isset($sample['avg']) ? $this->asFloat($sample['avg']) : null,
                    max: isset($sample['max']) ? $this->asFloat($sample['max']) : null,
                );
            })
            ->values();
    }

    /**
     * Re-key an aggregate collection by its event-type key, each row flattened to an
     * associative array so the mixed values can be coerced on read.
     *
     * @param  Collection<int, mixed>  $rows
     * @return array<string, array<array-key, mixed>>
     */
    private function keyByEvent(Collection $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $keyed[$this->asString($data['key'] ?? '')] = $data;
        }

        return $keyed;
    }

    /**
     * A single-type, single-aggregate total narrowed to a float. Pulse's aggregateTotal
     * only returns a Collection for a multi-type query, which this never issues; the
     * guard keeps the return type honest without a silencing cast.
     *
     * @param  'avg'|'count'|'max'|'min'|'sum'  $aggregate
     */
    private function total(string $type, string $aggregate): float
    {
        $value = $this->aggregateTotal($type, $aggregate);

        return $value instanceof Collection ? 0.0 : $value;
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function asFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
