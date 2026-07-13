{{-- Lazy placeholder for the delivery lists (recent queue + full table). --}}
<div class="wh-dash-table-placeholder" role="status" aria-label="{{ __('webhooks::dashboard.a11y.loading_deliveries') }}" wire:key="table-placeholder">
    <x-wirekit::skeleton.table :rows="5" />
</div>
