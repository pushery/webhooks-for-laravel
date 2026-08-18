{{-- Published stub: restyle with your design system (WireKit recommended) and
     place behind your own authorization. --}}
<div class="wh-subscriptions space-y-8">
    {{-- One form for both jobs. The component decides which it is from whether an endpoint
         is open for editing, so a save can never register a duplicate of the row it meant
         to correct. --}}
    <form wire:submit="save" class="space-y-4">
        <div>
            <label for="wh-name" class="block text-sm font-medium">{{ __('webhooks::management.form.name_label') }}</label>
            <input id="wh-name" type="text" wire:model="name" class="mt-1 block w-full rounded border px-3 py-2">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="wh-url" class="block text-sm font-medium">{{ __('webhooks::management.form.url_label') }}</label>
            <input id="wh-url" type="url" wire:model="url" placeholder="{{ __('webhooks::management.form.url_placeholder') }}" class="mt-1 block w-full rounded border px-3 py-2">
            @error('url') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <fieldset>
            <legend class="text-sm font-medium">{{ __('webhooks::management.form.event_types_legend') }}</legend>
            @forelse ($availableEventTypes as $type)
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="eventTypes" value="{{ $type }}"> {{ $type }}
                </label>
            @empty
                {{-- The path travels through the sentence as a placeholder, so a locale can
                     put it wherever its grammar wants it. --}}
                <p class="text-sm text-gray-500">{{ __('webhooks::management.form.event_types_empty', ['file' => 'config/webhooks.php']) }}</p>
            @endforelse
            {{-- Both keys: a per-item rule reports on the INDEXED path (eventTypes.0), so
                 a field-level lookup alone would refuse the save and show nothing. --}}
            @error('eventTypes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            @error('eventTypes.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </fieldset>

        @if ($editingId !== null)
            {{-- Only while editing: a registration is active by definition, and an
                 unchecked box on the create form would offer a state nobody asked for. --}}
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="isActive"> {{ __('webhooks::management.form.active_label') }}
            </label>
        @endif

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-white">
                {{ $editingId === null ? __('webhooks::management.form.submit') : __('webhooks::management.form.submit_update') }}
            </button>
            @if ($editingId !== null)
                <button type="button" wire:click="cancel" class="text-gray-600">{{ __('webhooks::management.actions.cancel') }}</button>
            @endif
        </div>
    </form>

    @if ($newSecret)
        <div class="wh-new-secret rounded border border-green-300 bg-green-50 p-4">
            {{-- A rotation says something a registration does not: the OLD secret keeps
                 verifying until the rotation window closes, which is what makes rotating
                 during an incident safe to do immediately. --}}
            <p class="text-sm font-medium">{{ $rotated ? __('webhooks::management.secret.rotated_heading') : __('webhooks::management.secret.heading') }}:</p>
            <code class="break-all">{{ $newSecret }}</code>
        </div>
    @endif

    <table class="w-full text-left text-sm">
        <thead>
            <tr>
                <th class="py-2">{{ __('webhooks::management.table.endpoint') }}</th>
                <th class="py-2">{{ __('webhooks::management.table.events') }}</th>
                <th class="py-2">{{ __('webhooks::management.table.status') }}</th>
                <th class="py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($subscriptions as $subscription)
                <tr wire:key="sub-{{ $subscription->id }}" class="border-t">
                    <th scope="row" class="py-2">
                        <span class="font-medium">{{ $subscription->name ?? '—' }}</span>
                        <span class="block text-gray-500">{{ $subscription->url }}</span>
                    </th>
                    <td class="py-2">{{ implode(', ', $subscription->event_types) }}</td>
                    <td class="py-2">
                        @if ($subscription->is_active)
                            <span class="text-green-700">{{ __('webhooks::management.subscription.active') }}</span>
                        @else
                            <span class="text-gray-500">{{ __('webhooks::management.subscription.disabled') }}</span>
                        @endif
                    </td>
                    <td class="py-2 text-right">
                        <button
                            type="button"
                            wire:click="edit({{ $subscription->id }})"
                            aria-label="{{ __('webhooks::management.a11y.edit_subscription', ['url' => $subscription->url]) }}"
                            class="text-indigo-600"
                        >{{ __('webhooks::management.subscription.edit') }}</button>
                        <button type="button" wire:click="toggle({{ $subscription->id }})" class="ml-3 text-indigo-600">
                            {{ $subscription->is_active ? __('webhooks::management.subscription.disable') : __('webhooks::management.subscription.enable') }}
                        </button>
                        {{-- Rotating starts a clock on the old secret rather than invalidating it,
                             but it is still a change every consumer of this endpoint has to follow,
                             so it is confirmed like the destructive action next to it. --}}
                        <button
                            type="button"
                            wire:click="rotate({{ $subscription->id }})"
                            wire:confirm="{{ __('webhooks::management.rotate_dialog.description') }}"
                            aria-label="{{ __('webhooks::management.a11y.rotate_subscription', ['url' => $subscription->url]) }}"
                            class="ml-3 text-indigo-600"
                        >{{ __('webhooks::management.subscription.rotate') }}</button>
                        {{-- Deleting an endpoint is irreversible and stops a live production
                             integration, so it is never a bare one-click destroy. This neutral stub
                             uses the browser confirm because it deliberately depends on no design
                             system; the WireKit variant (publish tag webhooks-ui-wirekit) confirms
                             with a real alert-dialog, which is the pattern to copy when you restyle
                             this view. --}}
                        <button
                            type="button"
                            wire:click="delete({{ $subscription->id }})"
                            wire:confirm="{{ __('webhooks::management.delete_dialog.description') }}"
                            aria-label="{{ __('webhooks::management.a11y.delete_subscription', ['url' => $subscription->url]) }}"
                            class="ml-3 text-red-600"
                        >{{ __('webhooks::management.subscription.delete') }}</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
