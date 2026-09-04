{{-- Published stub: restyle with your design system (WireKit recommended) and
     place behind your own authorization. --}}
<div class="wh-subscriptions space-y-8">
    {{-- One form for both jobs. The component decides which it is from whether an endpoint
         is open for editing, so a save can never register a duplicate of the row it meant
         to correct. --}}
    <form wire:submit="save" class="space-y-4">
        {{-- A permanent alert region, and permanent is the load-bearing word: a live region has
             to be in the DOM BEFORE its text appears, or the announcement is missed. This is
             also the only thing that says "the save was refused" at all — a message beside a
             field is heard when the field is reached, not when the form comes back. --}}
        <p role="alert" class="text-sm text-red-600">
            @if ($errors->any()){{ trans_choice('webhooks::management.form.error_summary', $errors->count()) }}@endif
        </p>

        {{-- aria-describedby and aria-invalid, conditionally, exactly as WireKit's own input
             does it. Without them the message is visible and absent from the accessibility
             tree: a screen reader announces the field as an ordinary valid text box and the
             error exists for sighted readers only (WCAG 3.3.1, 4.1.2, both Level A).

             This stub is meant to be restyled, but the wiring is semantics rather than
             styling — and it is the DEFAULT view of the shipped component, so a host that
             mounts the console without publishing anything gets exactly this form. --}}
        <div>
            <label for="wh-name" class="block text-sm font-medium">{{ __('webhooks::management.form.name_label') }}</label>
            <input id="wh-name" type="text" wire:model="name" class="mt-1 block w-full rounded border px-3 py-2" @error('name') aria-invalid="true" aria-describedby="wh-name-error" @enderror>
            @error('name') <p id="wh-name-error" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="wh-url" class="block text-sm font-medium">{{ __('webhooks::management.form.url_label') }}</label>
            <input id="wh-url" type="url" wire:model="url" placeholder="{{ __('webhooks::management.form.url_placeholder') }}" class="mt-1 block w-full rounded border px-3 py-2" @error('url') aria-invalid="true" aria-describedby="wh-url-error" @enderror>
            @error('url') <p id="wh-url-error" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <fieldset @error('eventTypes') aria-invalid="true" aria-describedby="wh-event-types-error" @enderror @error('eventTypes.*') aria-invalid="true" aria-describedby="wh-event-types-item-error" @enderror>
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
            {{-- The fieldset carries the description rather than a single checkbox: the error is
                 about the SET, and pointing every box at it would repeat the same sentence on
                 each one. --}}
            @error('eventTypes') <p id="wh-event-types-error" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            @error('eventTypes.*') <p id="wh-event-types-item-error" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
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
            {{-- A readonly input rather than a <code>, and that is about a rotation rather than
                 about styling. `SubscriptionManager::dehydrate()` clears `newSecret` on every
                 serialization: the plaintext exists in exactly one response and this console has no
                 reveal window to ask again with. A reader who loses one character while
                 hand-selecting a long token that wraps over several lines has no second chance —
                 the only recovery is a rotation, which sends every consumer of the endpoint into a
                 migration window nobody needed.

                 An input fixes the selection rather than the copying: focus it and Ctrl/Cmd-A
                 selects the FIELD instead of the page, and the value comes back as one string
                 with no wrap artefacts. It is also reachable by keyboard, which a <code> is not.

                 And it carries no `onfocus="this.select()"`, which is the obvious addition and the
                 one this package must not make. An inline handler is script under `script-src
                 'self'` without a nonce — a policy an application is entitled to choose — so the
                 browser refuses to run it, nothing throws, and the affordance is simply dead. That
                 failure has already happened three times on this surface; UiAssets states the third
                 and why the answer had to be one with no policy dependency left. Auto-select would
                 need a named factory in the served asset, which is a different change with a
                 different cost.

                 The heading is the input's LABEL rather than a paragraph beside it, so the field
                 has an accessible name without inventing a lang key for one. --}}
            <label for="wh-new-secret-value" class="block text-sm font-medium">{{ $rotated ? __('webhooks::management.secret.rotated_heading') : __('webhooks::management.secret.heading') }}</label>
            <input
                id="wh-new-secret-value"
                class="wh-new-secret-value mt-2 w-full break-all font-mono text-sm"
                type="text"
                readonly
                value="{{ $newSecret }}"
            >
        </div>
    @endif

    @if ($subscriptions->isEmpty())
        {{-- The zero-row case is the first thing every new install sees, so this stub ships the
             empty state rather than a header row over nothing. --}}
        <p class="wh-empty py-8 text-center text-sm">
            <span class="block font-medium">{{ __('webhooks::management.empty.no_subscriptions.title') }}</span>
            {{ __('webhooks::management.empty.no_subscriptions.description') }}
        </p>
    @else
    {{-- A scroll container of its own, so the overbreadth stops here instead of reaching the
         document. Without it the whole page grows a horizontal scrollbar on a narrow viewport
         and the header and page chrome slide away with the table, because it is the DOCUMENT
         that scrolls rather than the table.

         tabindex="0" is not optional once the container exists: a scrollable region has to be
         reachable by keyboard (WCAG 2.1.1), and an unfocusable overflow div is exactly the
         failure this attribute prevents. role and name come with it so the region announces as
         something rather than as an unlabeled group -- WireKit's own table wraps itself in the
         identical four attributes, which is why its twin of this screen never had the problem. --}}
    <div class="w-full min-w-0 overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('webhooks::management.a11y.subscriptions_table') }}">
    <table class="w-full text-left text-sm" aria-label="{{ __('webhooks::management.a11y.subscriptions_table') }}">
        <thead>
            <tr>
                <th class="px-3 py-2">{{ __('webhooks::management.table.endpoint') }}</th>
                <th class="px-3 py-2">{{ __('webhooks::management.table.events') }}</th>
                <th class="px-3 py-2">{{ __('webhooks::management.table.status') }}</th>
                {{-- Not an empty <th>: an empty header announces nothing, so a screen-reader
                     reader arriving in the last column is told only that it is the last one. --}}
                <th class="px-3 py-2"><span class="sr-only">{{ __('webhooks::management.table.actions') }}</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($subscriptions as $subscription)
                <tr wire:key="sub-{{ $subscription->id }}" class="border-t">
                    <th scope="row" class="px-3 py-2">
                        <span class="font-medium">{{ $subscription->name ?? '—' }}</span>
                        <span class="block text-gray-500">{{ $subscription->url }}</span>
                    </th>
                    <td class="px-3 py-2">{{ implode(', ', $subscription->eventTypeNames()) }}</td>
                    {{-- The same three bands as the WireKit stub, in this stub's own plain
                         classes. Both are kept in step deliberately: a host that starts on one
                         and moves to the other must not lose a state on the way. --}}
                    <td class="px-3 py-2">
                        @if (! $subscription->is_active)
                            <span class="text-gray-500">{{ __('webhooks::management.subscription.disabled') }}</span>
                        @elseif ($subscription->health_status === 'failing')
                            <span class="text-red-700">{{ __('webhooks::management.subscription.failing') }}</span>
                        @elseif ($subscription->health_status === 'degraded')
                            <span class="text-amber-700">{{ __('webhooks::management.subscription.degraded') }}</span>
                        @else
                            <span class="text-green-700">{{ __('webhooks::management.subscription.active') }}</span>
                        @endif

                        {{-- Same reason as the WireKit stub: "Disabled" has two causes that read
                             identically and call for opposite actions. --}}
                        @if ($subscription->wasAutoDisabled())
                            <span class="block text-gray-500">{{ __('webhooks::management.subscription.auto_disabled', ['count' => $subscription->consecutive_failures]) }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        <button
                            type="button"
                            wire:click="edit({{ $subscription->id }})"
                            aria-label="{{ __('webhooks::management.a11y.edit_subscription', ['url' => $subscription->url]) }}"
                            class="text-indigo-600"
                        >{{ __('webhooks::management.subscription.edit') }}</button>
                        {{-- Named per row like its neighbors; the visible word switches with the
                             row's state, so it is interpolated rather than described. --}}
                        @php($toggleLabel = $subscription->is_active ? __('webhooks::management.subscription.disable') : __('webhooks::management.subscription.enable'))
                        <button type="button" wire:click="toggle({{ $subscription->id }})" class="ml-3 text-indigo-600" aria-label="{{ __('webhooks::management.a11y.toggle_subscription', ['label' => $toggleLabel, 'url' => $subscription->url]) }}">
                            {{ $toggleLabel }}
                        </button>
                        {{-- Rotating starts a clock on the old secret rather than invalidating it,
                             but it is still a change every consumer of this endpoint has to follow,
                             so it is confirmed like the destructive action next to it. --}}
                        <button
                            type="button"
                            wire:click="rotate({{ $subscription->id }})"
                            wire:confirm="{{ __('webhooks::management.rotate_dialog.title') }}&#10;&#10;{{ __('webhooks::management.rotate_dialog.description') }}"
                            aria-label="{{ __('webhooks::management.a11y.rotate_subscription', ['label' => __('webhooks::management.subscription.rotate'), 'url' => $subscription->url]) }}"
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
                            wire:click="destroy({{ $subscription->id }})"
                            wire:confirm="{{ __('webhooks::management.delete_dialog.title') }}&#10;&#10;{{ __('webhooks::management.delete_dialog.description') }}"
                            aria-label="{{ __('webhooks::management.a11y.delete_subscription', ['url' => $subscription->url]) }}"
                            class="ml-3 text-red-600"
                        >{{ __('webhooks::management.subscription.delete') }}</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif

    {{ $subscriptions->links() }}
</div>
