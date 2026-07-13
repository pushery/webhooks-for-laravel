{{-- Create or edit one endpoint. Rendered inline (only when open) as a WireKit card.
     The URL field is a real http(s) URL input; the event types come from the
     configured catalog; validation messages surface through each field's error slot.
     Styled with WireKit tokens throughout. --}}
<div class="wh-portal-form" wire:key="endpoint-form">
    @if ($open)
        <x-wirekit::card>
            <x-wirekit::card.body>
                <form wire:submit="save">
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

                        <x-wirekit::field :label="__('webhooks::self-service.form.event_types_label')" :error="$errors->first('eventTypes') ?: null">
                            <x-wirekit::stack gap="xs">
                                @forelse ($availableEventTypes as $type)
                                    <x-wirekit::checkbox wire:model="eventTypes" value="{{ $type }}" label="{{ $type }}" />
                                @empty
                                    <x-wirekit::text size="sm" variant="muted">
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
                </form>
            </x-wirekit::card.body>
        </x-wirekit::card>
    @endif
</div>
