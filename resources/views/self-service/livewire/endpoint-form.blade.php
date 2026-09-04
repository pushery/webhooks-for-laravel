{{-- Create or edit one endpoint. Rendered inline (only when open) as a WireKit card.
     The URL field is a real http(s) URL input; the event types come from the
     configured catalog; validation messages surface through each field's error slot.
     Styled with WireKit tokens throughout. --}}
<div class="wh-portal-form" wire:key="endpoint-form">
    {{-- Permanent, so the announcement is not inserted together with its own region -- a live
         region has to be in the DOM before its text appears. It says the form opened, which
         nothing did before: the card simply materialized above the list. --}}
    <x-wirekit::visually-hidden role="status" aria-live="polite">
        @if ($open){{ $endpointId === null ? __('webhooks::self-service.form.new_heading') : __('webhooks::self-service.form.edit_heading') }}@endif
    </x-wirekit::visually-hidden>

    @if ($open)
        {{-- The form is inserted ABOVE the button that asked for it (page.blade.php puts the
             form before the list), so without this nothing moves: a keyboard reader tabs
             forward out of the list instead of into what they just opened, and a screen-reader
             reader is still positioned after a trigger that now sits after the whole form.

             A registered factory rather than an inline x-data, for the reason the file it
             lives in explains at length: under a policy without 'unsafe-eval' an inline object
             literal never parses and the panel is silently dead.

             @assets, so the script is requested once per page even though this markup is
             inside an @if -- the same file the drawer and the secret panel load. --}}
        @assets
            <script src="{{ \Pushery\Webhooks\Support\UiAssets::url() }}" defer></script>
        @endassets

        {{-- The scope and the focus target sit on our own element rather than on the card. An
             `x-data` written on a WireKit component is not an attribute on a div: Blade hands it to
             the component, which merges it onto the card's own root. The card sets an `x-data`
             there itself when its slot carries visible text with no card.body, and HTML keeps the
             first of two identical attributes, so one of the two scopes then stops existing, with
             no error and no log line. This panel uses card.body, so that branch is not taken today,
             which is exactly why the failure would arrive later, from a change somewhere else.
             `tabindex` moves with the scope, because the fallback in `init()` focuses `$el`
             when the panel holds no focusable control, and `$el` is this div now. --}}
        <div x-data="webhooksOpenedPanel" tabindex="-1">
        <x-wirekit::card>
            <x-wirekit::card.body>
                <x-wirekit::form wire:submit="save">
                    <x-wirekit::stack gap="md">
                        <x-wirekit::heading :level="3" size="sm">
                            {{ $endpointId === null ? __('webhooks::self-service.form.new_heading') : __('webhooks::self-service.form.edit_heading') }}
                        </x-wirekit::heading>

                        <x-wirekit::input
                            :label="__('webhooks::self-service.form.name_label')"
                            :hint="__('webhooks::self-service.form.name_hint')"
                            wire:model="name"
                            :error="$errors->first('name') ?: null"
                        />

                        <x-wirekit::input
                            type="url"
                            :label="__('webhooks::self-service.form.url_label')"
                            :placeholder="__('webhooks::self-service.form.url_placeholder')"
                            wire:model="url"
                            required
                            :error="$errors->first('url') ?: null"
                        />

                        <x-wirekit::field :label="__('webhooks::self-service.form.event_types_label')" :error="$errors->first('eventTypes') ?: ($errors->first('eventTypes.*') ?: null)">
                            <x-wirekit::stack gap="xs">
                                @forelse ($availableEventTypes as $type)
                                    <x-wirekit::checkbox wire:model="eventTypes" value="{{ $type }}" label="{{ $type }}" />
                                @empty
                                    <x-wirekit::text size="sm" intent="muted">
                                        {{ __('webhooks::self-service.form.no_event_types') }}
                                    </x-wirekit::text>
                                @endforelse
                            </x-wirekit::stack>
                        </x-wirekit::field>

                        <x-wirekit::toggle
                            wire:model="isActive"
                            :label="__('webhooks::self-service.form.active_label')"
                            :hint="__('webhooks::self-service.form.active_hint')"
                        />

                        <div class="flex items-center gap-[var(--gap-wk-sm)]">
                            <x-wirekit::button type="submit">
                                {{ $endpointId === null ? __('webhooks::self-service.form.register') : __('webhooks::self-service.form.save') }}
                            </x-wirekit::button>
                            <x-wirekit::button type="button" surface="ghost" intent="neutral" wire:click="cancel">
                                {{ __('webhooks::self-service.actions.cancel') }}
                            </x-wirekit::button>
                        </div>
                    </x-wirekit::stack>
                </x-wirekit::form>
            </x-wirekit::card.body>
        </x-wirekit::card>
        </div>
    @endif
</div>
