{{-- KPI ribbon. Polls on the configured cadence; the five tiles sit in a WireKit stats
     grid, never a hand-rolled one — the lazy placeholder renders into the same grid, so
     the layout never shifts when the metrics land. Each tile carries an intent, so the
     color carries the meaning (success / danger / warning) rather than a hardcoded
     palette. Retry rate adds a usage meter for an at-a-glance bar.

     The poll lives on the root element rather than on the stats grid itself: the cadence
     is interpolated into the DIRECTIVE NAME (wire:poll.30s), and Blade's component-tag
     compiler cannot parse an echo inside an attribute name. --}}
@php($metrics = $this->metrics)
<div
    class="wh-dash-kpis"
    wire:poll.{{ config('webhooks.dashboard.poll_interval', '30s') }}
    wire:key="kpi-cards"
>
    <x-wirekit::stats cols="5">
        <x-wirekit::stat :label="__('webhooks::dashboard.kpis.total')" :value="number_format($metrics->total)" intent="neutral" />

        <x-wirekit::stat :label="__('webhooks::dashboard.kpis.successful')" :value="number_format($metrics->delivered)" intent="success" />

        <x-wirekit::stat :label="__('webhooks::dashboard.kpis.failed')" :value="number_format($metrics->failed)" intent="danger" />

        <x-wirekit::stat :label="__('webhooks::dashboard.kpis.pending')" :value="number_format($metrics->pending)" intent="warning" />

        <x-wirekit::stat
            :label="__('webhooks::dashboard.kpis.retry_rate')"
            :value="$metrics->retryRate() . '%'"
            :intent="$metrics->retryRate() > 25 ? 'danger' : 'neutral'"
        >
            <x-wirekit::usage-meter
                :used="$metrics->retryRate()"
                :limit="100"
                unit="%"
                :showValue="false"
                :aria-label="__('webhooks::dashboard.a11y.retry_rate')"
            />
        </x-wirekit::stat>
    </x-wirekit::stats>
</div>
