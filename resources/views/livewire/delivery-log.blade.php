{{-- Published stub: restyle with your design system (WireKit recommended) and
     place behind your own authorization. --}}
<div class="wh-deliveries space-y-4">
    <div class="flex flex-wrap gap-4">
        <select wire:model.live="status" class="rounded border px-3 py-2">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="succeeded">Succeeded</option>
            <option value="failed">Failed</option>
            <option value="exhausted">Exhausted</option>
        </select>
        <input type="text" wire:model.live.debounce.300ms="eventType" placeholder="Filter by event type" class="rounded border px-3 py-2">
    </div>

    <table class="w-full text-left text-sm">
        <thead>
            <tr>
                <th class="py-2">Event</th>
                <th class="py-2">Status</th>
                <th class="py-2">Attempt</th>
                <th class="py-2">Code</th>
                <th class="py-2">When</th>
                <th class="py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($deliveries as $delivery)
                <tr wire:key="del-{{ $delivery->id }}" class="border-t">
                    <td class="py-2">{{ $delivery->event_type }}</td>
                    <td class="py-2">{{ $delivery->status->value }}</td>
                    <td class="py-2">{{ $delivery->attempt }}</td>
                    <td class="py-2">{{ $delivery->response_code ?? '—' }}</td>
                    <td class="py-2">{{ $delivery->created_at }}</td>
                    <td class="py-2 text-right">
                        <button type="button" wire:click="redeliver('{{ $delivery->id }}')" class="text-indigo-600">Redeliver</button>
                        <button type="button" wire:click="ping({{ $delivery->subscription_id }})" class="ml-3 text-indigo-600">Ping</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $deliveries->links() }}
</div>
