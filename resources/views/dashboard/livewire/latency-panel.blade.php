{{-- Latency panel: the window-level P50/P90/P95/P99 (computed live over the raw
     rows) as stat tiles, plus the per-hour p95 trend from the rollup as a compact
     token-styled sparkline of bars. --}}
@php($metrics = $this->metrics)
@php($trend = $this->trend)
@php($peak = $this->peakLatency)
<div class="wh-dash-latency" wire:key="latency-panel" wire:poll.{{ config('webhooks.dashboard.poll_interval', '30s') }}>
    <x-wirekit::card>
        <x-wirekit::card.header>
            <x-wirekit::heading :level="3" size="sm">{{ __('webhooks::dashboard.latency.title') }}</x-wirekit::heading>
        </x-wirekit::card.header>
        <x-wirekit::card.body>
            <x-wirekit::stats cols="4">
                <x-wirekit::stat label="P50" :value="number_format($metrics->p50, 1)" intent="neutral" />
                <x-wirekit::stat label="P90" :value="number_format($metrics->p90, 1)" intent="neutral" />
                <x-wirekit::stat label="P95" :value="number_format($metrics->p95, 1)" intent="info" />
                <x-wirekit::stat label="P99" :value="number_format($metrics->p99, 1)" intent="warning" />
            </x-wirekit::stats>

            @if ($trend->isNotEmpty())
                <div class="wh-dash-latency-trend mt-[var(--padding-wk-y-md)]">
                    <x-wirekit::text size="sm" intent="muted">{{ __('webhooks::dashboard.latency.p95_trend') }}</x-wirekit::text>
                    {{-- The sparkline height is a package custom property (defaulting to the
                         compact tier), so a host retunes both plots from one place. --}}
                    {{-- Same fix as the activity chart, same reason: fixed gaps plus a zero
                         flex-basis collapse every bar to 0px once the gaps outgrow the plot,
                         and the card's overflow-hidden means no scrollbar appears to say so.
                         See that view for the measured thresholds. --}}
                    <div
                        class="wh-dash-latency-plot mt-[var(--padding-wk-y-sm)] overflow-x-auto"
                        tabindex="0"
                        role="region"
                        aria-label="{{ __('webhooks::dashboard.a11y.latency_trend') }}"
                    >
                    <div class="flex h-[var(--wh-sparkline-height,4rem)] items-end gap-[var(--gap-wk-sm)]" role="img" aria-label="{{ __('webhooks::dashboard.a11y.latency_trend') }}">
                        @foreach ($trend as $row)
                            @php($p95 = (float) ($row->p95 ?? 0))
                            @php($height = (int) round($p95 / $peak * 100))
                            <div
                                class="wh-dash-latency-bar min-w-[3px] flex-1 rounded-t-[var(--radius-wk-sm)]"
                                style="height: {{ max($height, $p95 > 0 ? 2 : 0) }}%; background-color: var(--color-wk-accent);"
                                title="{{ number_format($p95, 1) }} ms"
                                wire:key="lat-{{ $row->bucket }}"
                            ></div>
                        @endforeach
                    </div>
                    </div>
                </div>
            @endif
        </x-wirekit::card.body>
    </x-wirekit::card>
</div>
