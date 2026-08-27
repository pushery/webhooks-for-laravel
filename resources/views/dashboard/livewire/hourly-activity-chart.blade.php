{{-- Hourly activity: delivered / pending / failed stacked per hour across the
     window. Rendered as token-styled stacked bars (the documented plain-Blade
     escape hatch) so it needs no compiled chart-adapter bundle to draw and stays
     server-renderable. Each bar carries an accessible label for screen readers. --}}
@php($rows = $this->hourly)
@php($peak = $this->peak)
<div class="wh-dash-activity" wire:key="hourly-activity" wire:poll.{{ config('webhooks.dashboard.poll_interval', '30s') }}>
    <x-wirekit::card>
        <x-wirekit::card.header>
            <x-wirekit::heading :level="3" size="sm">{{ __('webhooks::dashboard.activity.title') }}</x-wirekit::heading>
        </x-wirekit::card.header>
        <x-wirekit::card.body>
            @if ($rows->isEmpty())
                <x-wirekit::empty-state
                    icon="dashboard"
                    :title="__('webhooks::dashboard.empty.no_activity.title')"
                    :description="__('webhooks::dashboard.empty.no_activity.description')"
                />
            @else
                <div class="wh-dash-activity-legend mb-[var(--padding-wk-y-md)] flex flex-wrap gap-[var(--padding-wk-x-md)]">
                    <span class="inline-flex items-center gap-[var(--gap-wk-sm)] text-[length:var(--text-wk-sm)]">
                        <span class="inline-block size-3 rounded-[var(--radius-wk-sm)]" style="background-color: var(--color-wk-success);" aria-hidden="true"></span>
                        {{ __('webhooks::dashboard.activity.delivered') }}
                    </span>
                    <span class="inline-flex items-center gap-[var(--gap-wk-sm)] text-[length:var(--text-wk-sm)]">
                        <span class="inline-block size-3 rounded-[var(--radius-wk-sm)]" style="background-color: var(--color-wk-warning);" aria-hidden="true"></span>
                        {{ __('webhooks::dashboard.activity.pending') }}
                    </span>
                    <span class="inline-flex items-center gap-[var(--gap-wk-sm)] text-[length:var(--text-wk-sm)]">
                        <span class="inline-block size-3 rounded-[var(--radius-wk-sm)]" style="background-color: var(--color-wk-danger);" aria-hidden="true"></span>
                        {{ __('webhooks::dashboard.activity.failed') }}
                    </span>
                </div>

                {{-- The plot height is a package custom property, so a host that gives the
                     panel a different amount of room retunes it in one place instead of
                     forking the view. --}}
                {{-- ⚠️ THE SCROLL CONTAINER AND THE BAR MIN-WIDTH ARE ONE FIX, and without it the
                     plot went BLANK exactly when there was traffic to see. Each bar is
                     `flex: 1 1 0%` in a row with a fixed gap, and a flex gap never shrinks: once
                     the gaps alone are wider than the plot, the free space is negative, and
                     because the flex-basis is 0 each bar's scaled shrink factor is 0 too — so
                     nothing shrinks and every bar sits at 0px. The WireKit card around this is
                     `overflow-hidden`, so there was not even a scrollbar to hint at it.
                     Measured threshold: ~90 buckets at 1280px, ~42 on a 390px phone — and the
                     30d window can produce 720.

                     `tabindex="0"` with a role and a name because WCAG 2.1.1 requires a
                     scrolling region to be reachable by keyboard; WireKit's own table does the
                     same thing for the same reason. --}}
                <div
                    class="wh-dash-activity-plot overflow-x-auto"
                    tabindex="0"
                    role="region"
                    aria-label="{{ __('webhooks::dashboard.a11y.deliveries_per_hour') }}"
                >
                <div class="wh-dash-activity-bars flex h-[var(--wh-chart-height,10rem)] items-end gap-[var(--gap-wk-sm)]" role="list" aria-label="{{ __('webhooks::dashboard.a11y.deliveries_per_hour') }}">
                    @foreach ($rows as $row)
                        @php($total = (int) $row->total)
                        @php($delivered = (int) $row->delivered)
                        @php($pending = (int) $row->pending)
                        @php($failed = (int) $row->failed)
                        @php($barHeight = (int) round($total / $peak * 100))
                        @php($hour = \Pushery\Webhooks\Dashboard\DashboardTimezone::apply(\Illuminate\Support\Carbon::parse((string) $row->bucket))->settings(['locale' => app()->getLocale()])->translatedFormat(__('webhooks::dashboard.formats.hour_bucket')))
                        <div
                            class="wh-dash-activity-bar min-w-[3px] flex-1"
                            role="listitem"
                            aria-label="{{ __('webhooks::dashboard.a11y.hour_summary', ['hour' => $hour, 'total' => $total, 'delivered' => $delivered, 'pending' => $pending, 'failed' => $failed]) }}"
                            title="{{ __('webhooks::dashboard.activity.bar_title', ['hour' => $hour, 'total' => $total]) }}"
                            wire:key="hour-{{ $row->bucket }}"
                        >
                            <div class="flex h-full flex-col justify-end">
                                <div class="flex w-full flex-col-reverse overflow-hidden rounded-t-[var(--radius-wk-sm)]" style="height: {{ max($barHeight, $total > 0 ? 2 : 0) }}%;">
                                    @if ($delivered > 0)
                                        <div style="height: {{ round($delivered / max($total, 1) * 100) }}%; background-color: var(--color-wk-success);"></div>
                                    @endif
                                    @if ($pending > 0)
                                        <div style="height: {{ round($pending / max($total, 1) * 100) }}%; background-color: var(--color-wk-warning);"></div>
                                    @endif
                                    @if ($failed > 0)
                                        <div style="height: {{ round($failed / max($total, 1) * 100) }}%; background-color: var(--color-wk-danger);"></div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                </div>
            @endif
        </x-wirekit::card.body>
    </x-wirekit::card>
</div>
