{{-- Lazy placeholder for the KPI ribbon: five skeleton cards in the SAME WireKit stats
     grid the real ribbon renders into, so the panel never shifts when the metrics land.

     The class is `wh-dash-kpis-placeholder`, NOT `wh-dash-kpis`. It used to carry the
     resolved ribbon's own class, which made `.wh-dash-kpis` mean "ribbon OR skeleton" —
     so nothing could ask whether the ribbon had actually resolved without also asking
     about its text, and a check written by analogy with the other panels was true before
     anything had loaded. The three other placeholders here already followed the
     `*-placeholder` convention; this one was the exception. --}}
<div class="wh-dash-kpis-placeholder" role="status" aria-label="{{ __('webhooks::dashboard.a11y.loading_kpis') }}" wire:key="kpis-placeholder">
    <x-wirekit::stats cols="5">
        @for ($i = 0; $i < 5; $i++)
            <x-wirekit::skeleton.card />
        @endfor
    </x-wirekit::stats>
</div>
