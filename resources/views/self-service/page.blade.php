{{-- The full-page self-service portal shell. The header owns the page title; the
     endpoint list, the create/edit form and the signing-secret panel are independent
     Livewire panels wired together by events, so a save refreshes the list and a row
     can open the form or reveal its secret without a full navigation. Styled with
     WireKit tokens throughout.

     max-w-6xl, and it was max-w-4xl. This was the NARROWEST of the four portal-family
     screens while carrying the widest content: the endpoint table needs 1015px for its
     eight columns and five row actions, and max-w-4xl left it 864px. The table scrolls on its own, so nothing was unreachable and every arm was
     right to stay green -- but `Transform` sat off the right edge on a 1728px desktop
     with 432px of empty margin on either side, and a reader has no reason to look for
     a horizontal scrollbar inside a table on a wide screen.

     The value is the dashboard's, not a new one: that is the other screen whose primary
     content is a wide table. The health board and the transform editor are separate
     full-page routes at max-w-5xl; the portal sitting below both of them was the
     inconsistency. EndpointTableFitsItsPageTest holds the result as geometry. --}}
<div class="wh-portal mx-auto flex max-w-6xl flex-col gap-[var(--padding-wk-y-lg)] p-[var(--padding-wk-x-lg)]">
    <header>
        <x-wirekit::page-header :level="\Pushery\Webhooks\Support\UiVariant::pageHeadingLevel()" :title="__('webhooks::self-service.page.heading')" :description="__('webhooks::self-service.page.intro')">
            {{-- The condition sits INSIDE the slot. Livewire marks every conditional block in its
                 views with comments, and a block left in the default slot fills it: the page-header
                 then draws that slot instead of the title, and the heading renders empty. --}}
            <x-slot:actions>
                {{-- Absent when this shell is embedded without the portal's own routes. --}}
                @if ($healthBoardUrl !== null)
                    <x-wirekit::button :href="$healthBoardUrl" wire:navigate size="sm" surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}" intent="neutral">
                        {{ __('webhooks::self-service.page.health_link') }}
                    </x-wirekit::button>
                @endif
            </x-slot:actions>
        </x-wirekit::page-header>
    </header>

    <livewire:webhooks.self-service.endpoint-form wire:key="portal-form" />
    <livewire:webhooks.self-service.secret-panel wire:key="portal-secret" />
    <livewire:webhooks.self-service.endpoint-list wire:key="portal-list" />
    <livewire:webhooks.self-service.endpoint-deliveries wire:key="portal-deliveries" />

    <x-wirekit::toast-region />
</div>
