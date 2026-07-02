{{-- WireKit-styled stub (publish tag: webhooks-ui-wirekit). Requires pushery/wirekit
     and a Tailwind build that includes WireKit's @source; place behind your own
     authorization. Publish the neutral variant instead with the webhooks-ui tag. --}}
<x-wirekit::stack gap="md" class="wh-deliveries">
    <div class="flex flex-wrap items-end gap-4">
        <x-wirekit::select wire:model.live="status" label="Status" hideLabel>
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="succeeded">Succeeded</option>
            <option value="failed">Failed</option>
            <option value="exhausted">Exhausted</option>
        </x-wirekit::select>

        <x-wirekit::input
            wire:model.live.debounce.300ms="eventType"
            label="Event type"
            hideLabel
            placeholder="Filter by event type"
        />
    </div>

    <x-wirekit::table hoverable>
        <x-wirekit::table.head>
            <x-wirekit::table.row>
                <x-wirekit::table.th>Event</x-wirekit::table.th>
                <x-wirekit::table.th>Status</x-wirekit::table.th>
                <x-wirekit::table.th>Attempt</x-wirekit::table.th>
                <x-wirekit::table.th>Code</x-wirekit::table.th>
                <x-wirekit::table.th>When</x-wirekit::table.th>
                <x-wirekit::table.th align="right"></x-wirekit::table.th>
            </x-wirekit::table.row>
        </x-wirekit::table.head>
        <x-wirekit::table.body>
            @foreach ($deliveries as $delivery)
                @php
                    $intent = match ($delivery->status->value) {
                        'succeeded' => 'success',
                        'failed' => 'danger',
                        'exhausted' => 'warning',
                        default => 'neutral',
                    };
                @endphp
                <x-wirekit::table.row wire:key="del-{{ $delivery->id }}">
                    <x-wirekit::table.td>{{ $delivery->event_type }}</x-wirekit::table.td>
                    <x-wirekit::table.td>
                        <x-wirekit::badge :intent="$intent">{{ $delivery->status->value }}</x-wirekit::badge>
                    </x-wirekit::table.td>
                    <x-wirekit::table.td>{{ $delivery->attempt }}</x-wirekit::table.td>
                    <x-wirekit::table.td>{{ $delivery->response_code ?? '—' }}</x-wirekit::table.td>
                    <x-wirekit::table.td>{{ $delivery->created_at }}</x-wirekit::table.td>
                    <x-wirekit::table.td align="right">
                        <x-wirekit::button size="sm" surface="ghost" wire:click="redeliver('{{ $delivery->id }}')">Redeliver</x-wirekit::button>
                        <x-wirekit::button size="sm" surface="ghost" wire:click="ping({{ $delivery->subscription_id }})">Ping</x-wirekit::button>
                    </x-wirekit::table.td>
                </x-wirekit::table.row>
            @endforeach
        </x-wirekit::table.body>
    </x-wirekit::table>

    {{ $deliveries->links() }}
</x-wirekit::stack>
