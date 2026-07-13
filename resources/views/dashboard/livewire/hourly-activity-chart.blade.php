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
                <div class="wh-dash-activity-bars flex h-[var(--wh-chart-height,10rem)] items-end gap-[var(--gap-wk-sm)]" role="list" aria-label="{{ __('webhooks::dashboard.a11y.deliveries_per_hour') }}">
                    @foreach ($rows as $row)
                        @php($total = (int) $row->total)
                        @php($delivered = (int) $row->delivered)
                        @php($pending = (int) $row->pending)
                        @php($failed = (int) $row->failed)
                        @php($barHeight = (int) round($total / $peak * 100))
                        @php($hour = \Illuminate\Support\Carbon::parse((string) $row->bucket)->settings(['locale' => app()->getLocale()])->translatedFormat(__('webhooks::dashboard.formats.hour_bucket')))
                        <div
                            class="wh-dash-activity-bar flex-1"
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
            @endif
        </x-wirekit::card.body>
    </x-wirekit::card>
</div>
