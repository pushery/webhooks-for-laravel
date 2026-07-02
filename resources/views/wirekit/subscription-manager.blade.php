{{-- WireKit-styled stub (publish tag: webhooks-ui-wirekit). Requires pushery/wirekit
     and a Tailwind build that includes WireKit's @source; place behind your own
     authorization. Publish the neutral variant instead with the webhooks-ui tag. --}}
<x-wirekit::stack gap="lg" class="wh-subscriptions">
    <x-wirekit::card>
        <x-wirekit::card.body>
            <form wire:submit="create">
                <x-wirekit::stack gap="md">
                    <x-wirekit::input
                        label="Name"
                        wire:model="name"
                        :error="$errors->first('name') ?: null"
                    />

                    <x-wirekit::input
                        type="url"
                        label="Endpoint URL"
                        placeholder="https://example.com/webhooks"
                        wire:model="url"
                        :error="$errors->first('url') ?: null"
                    />

                    <x-wirekit::field label="Event types" :error="$errors->first('eventTypes') ?: null">
                        <x-wirekit::stack gap="xs">
                            @forelse ($availableEventTypes as $type)
                                <x-wirekit::checkbox wire:model="eventTypes" value="{{ $type }}" label="{{ $type }}" />
                            @empty
                                <x-wirekit::text size="sm" variant="muted">
                                    Configure event types in <x-wirekit::code>config/webhooks.php</x-wirekit::code>.
                                </x-wirekit::text>
                            @endforelse
                        </x-wirekit::stack>
                    </x-wirekit::field>

                    <div>
                        <x-wirekit::button type="submit">Register endpoint</x-wirekit::button>
                    </div>
                </x-wirekit::stack>
            </form>
        </x-wirekit::card.body>
    </x-wirekit::card>

    @if ($newSecret)
        <x-wirekit::alert variant="success" title="Signing secret (shown once — store it now)">
            <x-wirekit::code class="wh-new-secret break-all">{{ $newSecret }}</x-wirekit::code>
        </x-wirekit::alert>
    @endif

    <x-wirekit::table hoverable>
        <x-wirekit::table.head>
            <x-wirekit::table.row>
                <x-wirekit::table.th>Endpoint</x-wirekit::table.th>
                <x-wirekit::table.th>Events</x-wirekit::table.th>
                <x-wirekit::table.th>Status</x-wirekit::table.th>
                <x-wirekit::table.th align="right"></x-wirekit::table.th>
            </x-wirekit::table.row>
        </x-wirekit::table.head>
        <x-wirekit::table.body>
            @foreach ($subscriptions as $subscription)
                <x-wirekit::table.row wire:key="sub-{{ $subscription->id }}">
                    <x-wirekit::table.td>
                        <x-wirekit::stack gap="none">
                            <x-wirekit::text weight="medium">{{ $subscription->name ?? '—' }}</x-wirekit::text>
                            <x-wirekit::text size="sm" variant="muted">{{ $subscription->url }}</x-wirekit::text>
                        </x-wirekit::stack>
                    </x-wirekit::table.td>
                    <x-wirekit::table.td>{{ implode(', ', $subscription->event_types) }}</x-wirekit::table.td>
                    <x-wirekit::table.td>
                        <x-wirekit::badge :intent="$subscription->is_active ? 'success' : 'neutral'">
                            {{ $subscription->is_active ? 'Active' : 'Disabled' }}
                        </x-wirekit::badge>
                    </x-wirekit::table.td>
                    <x-wirekit::table.td align="right">
                        <x-wirekit::button size="sm" surface="ghost" wire:click="toggle({{ $subscription->id }})">
                            {{ $subscription->is_active ? 'Disable' : 'Enable' }}
                        </x-wirekit::button>
                        <x-wirekit::button size="sm" surface="ghost" intent="danger" wire:click="delete({{ $subscription->id }})">
                            Delete
                        </x-wirekit::button>
                    </x-wirekit::table.td>
                </x-wirekit::table.row>
            @endforeach
        </x-wirekit::table.body>
    </x-wirekit::table>
</x-wirekit::stack>
