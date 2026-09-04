{{-- Latency panel: the window-level P50/P90/P95/P99 (computed live over the raw
     rows) as stat tiles, plus the per-hour p95 trend from the rollup as a compact
     token-styled sparkline of bars. --}}
@php($metrics = $this->metrics)
@php($trend = $this->trend)
@php($peak = $this->peakLatency)
<div class="wh-dash-latency" wire:key="latency-panel" wire:poll.{{ config('webhooks.dashboard.poll_interval', '30s') }}>
    <x-wirekit::card>
        <x-wirekit::card.header>
            <x-wirekit::heading :level="2" size="sm">{{ __('webhooks::dashboard.latency.title') }}</x-wirekit::heading>
        </x-wirekit::card.header>
        <x-wirekit::card.body>
            <x-wirekit::stats cols="4">
                <x-wirekit::stat label="P50" :value="\Pushery\Webhooks\Support\LocalizedNumber::format($metrics->p50, 1)" intent="neutral" />
                <x-wirekit::stat label="P90" :value="\Pushery\Webhooks\Support\LocalizedNumber::format($metrics->p90, 1)" intent="neutral" />
                <x-wirekit::stat label="P95" :value="\Pushery\Webhooks\Support\LocalizedNumber::format($metrics->p95, 1)" intent="info" />
                <x-wirekit::stat label="P99" :value="\Pushery\Webhooks\Support\LocalizedNumber::format($metrics->p99, 1)" intent="warning" />
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
                    {{-- A LIST, not an image, and every bar names its own value. As a role="img"
                         with one label the chart announced its title and nothing else: the
                         milliseconds lived only in a `title` attribute, which a screen reader
                         does not read off a div and which a touch device cannot hover to reach.
                         So the one number an operator opens this panel for -- when did the
                         latency run away -- was available to a mouse and to nothing else.

                         The activity chart directly above already does it this way; this is the
                         pattern being applied, not invented. The `title` stays as a convenience
                         for the pointer rather than as the only channel. --}}
                    <div class="flex h-[var(--wh-sparkline-height,4rem)] items-end gap-[var(--gap-wk-sm)]" role="list" aria-label="{{ __('webhooks::dashboard.a11y.latency_trend') }}">
                        @foreach ($trend as $row)
                            @php($p95 = (float) ($row->p95 ?? 0))
                            @php($height = (int) round($p95 / $peak * 100))
                            @php($hour = \Pushery\Webhooks\Dashboard\DashboardTimezone::apply(\Illuminate\Support\Carbon::parse((string) $row->bucket))->settings(['locale' => app()->getLocale()])->translatedFormat(__('webhooks::dashboard.formats.hour_bucket')))
                            <div
                                class="wh-dash-latency-bar min-w-[3px] flex-1 rounded-t-[var(--radius-wk-sm)] bg-[var(--color-wk-accent)]"
                                style="height: {{ max($height, $p95 > 0 ? 2 : 0) }}%"
                                role="listitem"
                                aria-label="{{ __('webhooks::dashboard.a11y.latency_bar', ['hour' => $hour, 'value' => \Pushery\Webhooks\Support\LocalizedNumber::format($p95, 1)]) }}"
                                title="{{ \Pushery\Webhooks\Support\LocalizedNumber::format($p95, 1) }} ms"
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
