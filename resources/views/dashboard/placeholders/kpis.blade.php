{{-- Lazy placeholder for the KPI ribbon: five skeleton cards in the SAME WireKit stats
     grid the real ribbon renders into, so the panel never shifts when the metrics land. --}}
<div class="wh-dash-kpis" role="status" aria-label="{{ __('webhooks::dashboard.a11y.loading_kpis') }}" wire:key="kpis-placeholder">
    <x-wirekit::stats cols="5">
        @for ($i = 0; $i < 5; $i++)
            <x-wirekit::skeleton.card />
        @endfor
    </x-wirekit::stats>
</div>
