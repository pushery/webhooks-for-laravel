{{-- The full-page dashboard shell. Tabs are wire:navigate links (SPA feel, no
     client router); the window is part of the key of every panel that takes one, so
     changing it remounts them. Each panel is a lazy, independently-polling Livewire
     component. --}}
<div class="wh-dash mx-auto flex max-w-6xl flex-col gap-[var(--padding-wk-y-lg)] p-[var(--padding-wk-x-lg)]">
    <header class="flex flex-wrap items-center justify-between gap-[var(--padding-wk-x-md)]">
        <x-wirekit::heading :level="1" size="lg">{{ __('webhooks::dashboard.heading') }}</x-wirekit::heading>

        {{-- WireKit's segmented-control, driven optimistically through selectWindow()
             rather than by a wire:model.

             A hand-rolled button group stood here, and its stated reason — "the component
             forwards only a bare `wire:model` to its hidden input" — was fixed upstream in
             WireKit v2.12.0 and is unreachable below the 2.38 floor this package enforces.
             It was a rebuild of a component we ship.

             No `optimistic` prop, and that is measured rather than chosen. The optimistic layer
             lives in a separate WireKit bundle (dist/wirekit-optimistic.js) that these screens do
             not load; with the prop set, a real browser reports `wirekitOptimistic is not defined`
             and four more before the page finishes. Using it would make a second asset a
             requirement for every consumer, to move a three-option control a round trip sooner.

             The binding needs `updatedWindow()`, and without it this would be a regression.
             `$window` carries `#[Url]`, so it is client input; mount() screens it against the
             configured set, and mount() runs once. The hand-rolled group this replaced went through
             the allowlisted `selectWindow()` on every click. A binding writes the property directly
             instead, so the same screening now sits in the update hook — see the component. --}}
        <x-wirekit::segmented-control
            class="wh-dash-windows"
            {{-- Named because it is live-bound: the hidden input a wire:model writes through is
                 what a form would submit, and LiveBoundFieldsAreNamedTest holds every shipped
                 control to that. --}}
            name="window"
            size="sm"
            :label="__('webhooks::dashboard.a11y.time_window')"
            :options="array_combine($windows, $windows)"
            :value="$window"
            wire:model.live="window"
        />
    </header>

    <nav class="wh-dash-tabs flex flex-wrap gap-[var(--padding-wk-x-md)] border-b-[length:var(--border-wk-width)] border-[color:var(--color-wk-border)]" aria-label="{{ __('webhooks::dashboard.a11y.sections') }}">
        @foreach ($tabs as $t)
            <a
                href="{{ route('webhooks.dashboard', ['tab' => $t, 'window' => $window]) }}"
                wire:navigate
                @class([
                    {{-- A FLOOR, not a repair — and the difference is measured. On a styled page these
                         links come out at 36px, well clear of the 24px minimum in WCAG 2.5.8, so nothing
                         here is broken today. What is not guaranteed is that they stay there: the height
                         is padding token plus line height, and BOTH are values a host re-themes. Pinning
                         the floor makes the guarantee structural instead of incidental, and costs a
                         host nothing that it did not already agree to. 24px is WireKit's own number for
                         this; its input and combobox affordances carry the same pair. --}}
                    'wh-dash-tab inline-flex items-center min-h-[24px] px-[var(--padding-wk-x-md)] py-[var(--padding-wk-y-sm)]',
                    'border-b-[length:var(--border-wk-width)] border-[color:var(--color-wk-accent)] font-[number:var(--font-wk-heading-weight)]' => $tab === $t,
                    'text-[color:var(--color-wk-text-muted)]' => $tab !== $t,
                ])
                @if ($tab === $t) aria-current="page" @endif
            >{{ __('webhooks::dashboard.tabs.'.$t) }}</a>
        @endforeach
    </nav>

    {{-- The window is part of the key on every panel that takes one, and it has to be. A broadcast
         cannot reach a panel that is still lazy: Livewire's client drops the event outright for an
         unresolved lazy component. That panel then resolves through its own `__lazyLoad`, which
         resurrects the mount parameters encoded into the placeholder when the page first rendered —
         so it comes up on the old window and stays there. The header reads 7d, the panel counts
         24h, and nothing reports it. The re-render does not fix it either: a child whose key is
         unchanged is replaced by an empty stub rather than mounted again, so the encoded parameters
         never move.

         Putting the window in the key means the child is a different child on every window, so it
         is mounted fresh — with the new window, and a placeholder that encodes the new window. The
         panels below that take no window keep a stable key.

         The cost is deliberate: switching the window remounts these four, so their skeleton shows
         for one round trip instead of the old numbers being patched in place. That is the honest
         picture — during that round trip the old numbers are not the new window's, which is exactly
         the confusion this fixes. --}}
    {{-- The counts below are summed from a materialized rollup that only webhooks:refresh-metrics
         advances; the latency percentiles beside them are computed live. With the refresh
         stopped, frozen counts sit next to current percentiles that make them look plausible,
         and nothing said which was which.
         The lag is measured against the newest DELIVERY, not against the clock, so an endpoint
         with no traffic never reports one -- and it only shows past twice the configured
         cadence, because one cadence behind is simply between two runs. --}}
    @if ($this->rollupLagMinutes() !== null)
        <x-wirekit::alert intent="warning" role="status">
            {{ __('webhooks::dashboard.rollup_stale', ['minutes' => $this->rollupLagMinutes()]) }}
        </x-wirekit::alert>
    @endif

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
