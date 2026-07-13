{{-- Lazy placeholder for the endpoint list. --}}
<div class="wh-portal-list-placeholder" role="status" aria-label="{{ __('webhooks::self-service.a11y.loading_endpoints') }}" wire:key="endpoint-list-placeholder">
    <x-wirekit::skeleton.table :rows="4" />
</div>
