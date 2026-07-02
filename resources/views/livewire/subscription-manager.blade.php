{{-- Published stub: restyle with your design system (WireKit recommended) and
     place behind your own authorization. --}}
<div class="wh-subscriptions space-y-8">
    <form wire:submit="create" class="space-y-4">
        <div>
            <label for="wh-name" class="block text-sm font-medium">Name</label>
            <input id="wh-name" type="text" wire:model="name" class="mt-1 block w-full rounded border px-3 py-2">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="wh-url" class="block text-sm font-medium">Endpoint URL</label>
            <input id="wh-url" type="url" wire:model="url" placeholder="https://example.com/webhooks" class="mt-1 block w-full rounded border px-3 py-2">
            @error('url') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <fieldset>
            <legend class="text-sm font-medium">Event types</legend>
            @forelse ($availableEventTypes as $type)
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="eventTypes" value="{{ $type }}"> {{ $type }}
                </label>
            @empty
                <p class="text-sm text-gray-500">Configure event types in <code>config/webhooks.php</code>.</p>
            @endforelse
            @error('eventTypes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </fieldset>

        <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white">Register endpoint</button>
    </form>

    @if ($newSecret)
        <div class="wh-new-secret rounded border border-green-300 bg-green-50 p-4">
            <p class="text-sm font-medium">Signing secret (shown once — store it now):</p>
            <code class="break-all">{{ $newSecret }}</code>
        </div>
    @endif

    <table class="w-full text-left text-sm">
        <thead>
            <tr>
                <th class="py-2">Endpoint</th>
                <th class="py-2">Events</th>
                <th class="py-2">Status</th>
                <th class="py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($subscriptions as $subscription)
                <tr wire:key="sub-{{ $subscription->id }}" class="border-t">
                    <td class="py-2">
                        <span class="font-medium">{{ $subscription->name ?? '—' }}</span>
                        <span class="block text-gray-500">{{ $subscription->url }}</span>
                    </td>
                    <td class="py-2">{{ implode(', ', $subscription->event_types) }}</td>
                    <td class="py-2">
                        @if ($subscription->is_active)
                            <span class="text-green-700">Active</span>
                        @else
                            <span class="text-gray-500">Disabled</span>
                        @endif
                    </td>
                    <td class="py-2 text-right">
                        <button type="button" wire:click="toggle({{ $subscription->id }})" class="text-indigo-600">
                            {{ $subscription->is_active ? 'Disable' : 'Enable' }}
                        </button>
                        <button type="button" wire:click="delete({{ $subscription->id }})" class="ml-3 text-red-600">Delete</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
