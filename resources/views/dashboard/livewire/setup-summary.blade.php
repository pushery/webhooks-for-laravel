{{-- Setup summary: how many endpoints the tenant has registered and how many are
     active. Doubles as the onboarding hint when nothing is registered yet. --}}
@php($summary = $this->summary)
<x-wirekit::card class="wh-dash-setup" wire:key="setup-summary">
    <x-wirekit::card.header>
        <x-wirekit::heading :level="3" size="sm">{{ __('webhooks::dashboard.setup.title') }}</x-wirekit::heading>
    </x-wirekit::card.header>
    <x-wirekit::card.body>
        @if ($summary['total'] === 0)
            <x-wirekit::empty-state
                icon="globe"
                :title="__('webhooks::dashboard.empty.no_endpoints.title')"
                :description="__('webhooks::dashboard.empty.no_endpoints.description')"
            />
        @else
            <x-wirekit::stats cols="3">
                <x-wirekit::stat :label="__('webhooks::dashboard.setup.total')" :value="number_format($summary['total'])" intent="neutral" />
                <x-wirekit::stat :label="__('webhooks::dashboard.setup.active')" :value="number_format($summary['active'])" intent="success" />
                <x-wirekit::stat :label="__('webhooks::dashboard.setup.disabled')" :value="number_format($summary['disabled'])" intent="warning" />
            </x-wirekit::stats>
        @endif
    </x-wirekit::card.body>
</x-wirekit::card>
