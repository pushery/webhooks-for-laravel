{{-- The full-page dashboard shell. Tabs are wire:navigate links (SPA feel, no
     client router); the window is part of the key of every panel that takes one, so
     changing it remounts them. Each panel is a lazy, independently-polling Livewire
     component. --}}
<div class="wh-dash mx-auto flex max-w-6xl flex-col gap-[var(--padding-wk-y-lg)] p-[var(--padding-wk-x-lg)]">
    <header class="flex flex-wrap items-center justify-between gap-[var(--padding-wk-x-md)]">
        <x-wirekit::heading :level="1" size="lg">{{ __('webhooks::dashboard.heading') }}</x-wirekit::heading>

        {{-- A hand-rolled toggle group rather than WireKit's segmented-control: the
             control has to write straight back to a live Livewire property, and the
             component forwards only a bare `wire:model` to its hidden input — a
             `wire:model.live` never reaches it, so the window switch would not take
             effect until the next unrelated round trip. --}}
        <div class="wh-dash-windows inline-flex items-center gap-[var(--gap-wk-sm)]" role="group" aria-label="{{ __('webhooks::dashboard.a11y.time_window') }}">
            @foreach ($windows as $option)
                <x-wirekit::button
                    size="sm"
                    :surface="$window === $option ? 'filled' : 'ghost'"
                    :intent="$window === $option ? 'primary' : 'neutral'"
                    wire:click="selectWindow('{{ $option }}')"
                    :aria-pressed="$window === $option ? 'true' : 'false'"
                >{{ $option }}</x-wirekit::button>
            @endforeach
        </div>
    </header>

    <nav class="wh-dash-tabs flex flex-wrap gap-[var(--padding-wk-x-md)] border-b-[length:var(--border-wk-width)] border-[color:var(--color-wk-border)]" aria-label="{{ __('webhooks::dashboard.a11y.sections') }}">
        @foreach ($tabs as $t)
            <a
                href="{{ route('webhooks.dashboard', ['tab' => $t, 'window' => $window]) }}"
                wire:navigate
                @class([
                    'wh-dash-tab px-[var(--padding-wk-x-md)] py-[var(--padding-wk-y-sm)]',
                    'border-b-[length:var(--border-wk-width)] border-[color:var(--color-wk-accent)] font-[number:var(--font-wk-heading-weight)]' => $tab === $t,
                    'text-[color:var(--color-wk-text-muted)]' => $tab !== $t,
                ])
                @if ($tab === $t) aria-current="page" @endif
            >{{ __('webhooks::dashboard.tabs.'.$t) }}</a>
        @endforeach
    </nav>

    {{-- ⚠️ THE WINDOW IS PART OF THE KEY ON EVERY PANEL THAT TAKES ONE, AND IT HAS TO BE.
         A broadcast cannot reach a panel that is still lazy: Livewire's client drops the
         event outright for an unresolved lazy component. That panel then resolves through
         its own `__lazyLoad`, which RESURRECTS the mount parameters encoded into the
         placeholder when the page first rendered — so it comes up on the OLD window and
         stays there. The header reads 7d, the panel counts 24h, and nothing reports it.
         The re-render does not fix it either: a child whose key is unchanged is replaced
         by an empty stub rather than mounted again, so the encoded parameters never move.

         Putting the window in the key means the child is a different child on every
         window, so it is mounted fresh — with the new window, and a placeholder that
         encodes the new window. The panels below that take no window keep a stable key.

         The cost is deliberate: switching the window remounts these four, so their
         skeleton shows for one round trip instead of the old numbers being patched in
         place. That is the honest picture — during that round trip the old numbers are
         not the new window's, which is exactly the confusion this fixes. --}}
    <div class="wh-dash-body">
        @if ($tab === 'overview')
            <div class="flex flex-col gap-[var(--padding-wk-y-lg)]">
                <livewire:webhooks.dashboard.kpi-cards :window="$window" wire:key="ov-kpis-{{ $window }}" />

                <div class="grid grid-cols-1 gap-[var(--padding-wk-y-lg)] lg:grid-cols-3">
                    <div class="lg:col-span-2 flex flex-col gap-[var(--padding-wk-y-lg)]">
                        <livewire:webhooks.dashboard.hourly-activity-chart :window="$window" wire:key="ov-activity-{{ $window }}" />
                        <livewire:webhooks.dashboard.latency-panel :window="$window" wire:key="ov-latency-{{ $window }}" />
                    </div>
                    <div class="flex flex-col gap-[var(--padding-wk-y-lg)]">
                        <livewire:webhooks.dashboard.setup-summary wire:key="ov-setup" />
                        <livewire:webhooks.dashboard.top-events :window="$window" wire:key="ov-top-{{ $window }}" />
                        <livewire:webhooks.dashboard.recent-queue wire:key="ov-recent" />
                    </div>
                </div>
            </div>
        @elseif ($tab === 'webhooks')
            <div class="flex flex-col gap-[var(--padding-wk-y-lg)]">
                <livewire:webhooks.dashboard.deliveries-table wire:key="wh-table" />
                <livewire:webhooks.dashboard.delivery-detail-drawer wire:key="wh-drawer" />
            </div>
        @elseif ($tab === 'queue')
            <livewire:webhooks.dashboard.recent-queue :limit="25" wire:key="q-recent" />
        @else
            <x-wirekit::card class="wh-dash-docs">
                <x-wirekit::card.body>
                    <x-wirekit::heading :level="2" size="md">{{ __('webhooks::dashboard.docs.title') }}</x-wirekit::heading>
                    <x-wirekit::text class="mt-[var(--padding-wk-y-sm)]">
                        {{ __('webhooks::dashboard.docs.body') }}
                    </x-wirekit::text>
                </x-wirekit::card.body>
            </x-wirekit::card>
        @endif
    </div>

    <x-wirekit::toast-region />
</div>
