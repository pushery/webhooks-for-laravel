{{-- The full-page self-service portal shell. The header owns the page title; the
     endpoint list, the create/edit form and the signing-secret panel are independent
     Livewire panels wired together by events, so a save refreshes the list and a row
     can open the form or reveal its secret without a full navigation. Styled with
     WireKit tokens throughout. --}}
<div class="wh-portal mx-auto flex max-w-4xl flex-col gap-[var(--padding-wk-y-lg)] p-[var(--padding-wk-x-lg)]">
    <header class="flex flex-wrap items-start justify-between gap-[var(--padding-wk-x-md)]">
        <div class="flex flex-col gap-[var(--padding-wk-y-sm)]">
            <x-wirekit::heading :level="1" size="lg">{{ __('webhooks::self-service.page.heading') }}</x-wirekit::heading>
            <x-wirekit::text variant="muted">{{ __('webhooks::self-service.page.intro') }}</x-wirekit::text>
        </div>
        <x-wirekit::button :href="route('webhooks.self-service.health')" wire:navigate size="sm" surface="ghost" intent="neutral">
            {{ __('webhooks::self-service.page.health_link') }}
        </x-wirekit::button>
    </header>

    <livewire:webhooks.self-service.endpoint-form wire:key="portal-form" />
    <livewire:webhooks.self-service.secret-panel wire:key="portal-secret" />
    <livewire:webhooks.self-service.endpoint-list wire:key="portal-list" />

    <x-wirekit::toast-region />
</div>
