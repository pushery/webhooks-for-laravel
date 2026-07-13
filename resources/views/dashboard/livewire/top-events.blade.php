{{-- Top events: the busiest event types in the window, ranked by count. --}}
@php($events = $this->events)
<div class="wh-dash-top-events" wire:key="top-events" wire:poll.{{ config('webhooks.dashboard.poll_interval', '30s') }}>
    <x-wirekit::card>
        <x-wirekit::card.header>
            <x-wirekit::heading :level="3" size="sm">{{ __('webhooks::dashboard.top_events.title') }}</x-wirekit::heading>
        </x-wirekit::card.header>
        <x-wirekit::card.body>
            @if ($events->isEmpty())
                <x-wirekit::empty-state
                    icon="sort-desc"
                    :title="__('webhooks::dashboard.empty.no_events.title')"
                    :description="__('webhooks::dashboard.empty.no_events.description')"
                />
            @else
                {{-- Keyed by the event type, never by position: the ranking re-orders between
                     polls, and a positional key would make the morph rewrite each row in place
                     instead of moving it. --}}
                <ul class="wh-dash-top-events-list flex flex-col gap-[var(--padding-wk-y-sm)]">
                    @foreach ($events as $event)
                        <li class="flex items-center justify-between gap-[var(--padding-wk-x-md)]" wire:key="te-{{ $event->event_type }}">
                            <x-wirekit::text weight="medium" truncate>{{ $event->event_type }}</x-wirekit::text>
                            <x-wirekit::badge intent="neutral">{{ number_format((int) $event->total) }}</x-wirekit::badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-wirekit::card.body>
    </x-wirekit::card>
</div>
