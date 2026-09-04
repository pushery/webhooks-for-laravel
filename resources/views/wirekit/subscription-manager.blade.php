{{-- WireKit-styled stub (publish tag: webhooks-ui-wirekit). Requires pushery/wirekit
     and a Tailwind build that scans both packages' views (see the "Styling the UI" guide
     at https://docs.pushery.com/webhooks-for-laravel/guides/styling-the-ui); place behind
     your own authorization. Publish the neutral variant
     instead with the webhooks-ui tag. --}}
{{-- Pairs badly with the wirekit.delivery-log stub on the same page: its filter is a WireKit select
     bound with wire:model.live, and that combination makes the confirm action of the delete dialogs
     below unclickable. The dialog opens, the click never lands, nothing is logged. Reported
     upstream; the comment in that stub carries the detail. --}}
<x-wirekit::stack gap="lg" class="wh-subscriptions">
    <x-wirekit::card>
        <x-wirekit::card.body>
            {{-- One form for both jobs. The component decides which it is from whether an
                 endpoint is open for editing, so a save can never register a duplicate of
                 the row it meant to correct. --}}
            <x-wirekit::form wire:submit="save">
                <x-wirekit::stack gap="md">
                    <x-wirekit::input
                        :label="__('webhooks::management.form.name_label')"
                        wire:model="name"
                        :error="$errors->first('name') ?: null"
                    />

                    <x-wirekit::input
                        type="url"
                        :label="__('webhooks::management.form.url_label')"
                        :placeholder="__('webhooks::management.form.url_placeholder')"
                        wire:model="url"
                        :error="$errors->first('url') ?: null"
                    />

                    <x-wirekit::field :label="__('webhooks::management.form.event_types_legend')" :error="$errors->first('eventTypes') ?: ($errors->first('eventTypes.*') ?: null)">
                        <x-wirekit::stack gap="xs">
                            @forelse ($availableEventTypes as $type)
                                <x-wirekit::checkbox wire:model="eventTypes" value="{{ $type }}" label="{{ $type }}" />
                            @empty
                                {{-- The path travels through the sentence as a placeholder, so a locale
                                     can put it wherever its grammar wants it. --}}
                                <x-wirekit::text size="sm" intent="muted">
                                    {{ __('webhooks::management.form.event_types_empty', ['file' => 'config/webhooks.php']) }}
                                </x-wirekit::text>
                            @endforelse
                        </x-wirekit::stack>
                    </x-wirekit::field>

                    @if ($editingId !== null)
                        {{-- Only while editing: a registration is active by definition, and an
                             unchecked box on the create form would offer a state nobody asked for. --}}
                        <x-wirekit::checkbox wire:model="isActive" :label="__('webhooks::management.form.active_label')" />
                    @endif

                    <x-wirekit::row gap="sm">
                        <x-wirekit::button type="submit">
                            {{ $editingId === null ? __('webhooks::management.form.submit') : __('webhooks::management.form.submit_update') }}
                        </x-wirekit::button>
                        @if ($editingId !== null)
                            <x-wirekit::button type="button" surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}" wire:click="cancel">
                                {{ __('webhooks::management.actions.cancel') }}
                            </x-wirekit::button>
                        @endif
                    </x-wirekit::row>
                </x-wirekit::stack>
            </x-wirekit::form>
        </x-wirekit::card.body>
    </x-wirekit::card>

    @if ($newSecret)
        {{-- A rotation says something a registration does not: the OLD secret keeps verifying
             until the rotation window closes, which is what makes rotating during an incident
             safe to do immediately. --}}
        {{-- The copy control is not decoration here. dehydrate() clears newSecret on every
             dehydrate, on purpose, so the plaintext exists in exactly one response and this console
             has no reveal window to ask again with. A reader who cannot get it out of this one
             rendering has to rotate, which puts every consumer of the endpoint into a migration
             window nobody needed. Selecting a 50-character token rendered `break-all` across
             several lines with a mouse, without losing a character, is precisely where that goes
             wrong. --}}
        <x-wirekit::alert intent="success" :title="$rotated ? __('webhooks::management.secret.rotated_heading') : __('webhooks::management.secret.heading')">
            <div class="flex flex-wrap items-center gap-[var(--gap-wk-sm)]">
                <x-wirekit::code class="wh-new-secret break-all">{{ $newSecret }}</x-wirekit::code>
                {{-- A LABELED button, not an icon-only one: its accessible name then comes from
                     the visible text, so the name and the label cannot drift apart. The pair that
                     has to hold together is the name and the VALUE — passing the secret as an
                     undeclared attribute would leave the button unnamed and copy an empty string,
                     and either half alone still looks right on screen. --}}
                <x-wirekit::clipboard-button
                    class="wh-new-secret-copy"
                    :value="$newSecret"
                    :copied-text="__('webhooks::management.secret.copied')"
                >{{ __('webhooks::management.secret.copy') }}</x-wirekit::clipboard-button>
            </div>
        </x-wirekit::alert>
    @endif

    @if ($subscriptions->isEmpty())
        {{-- The zero-row case is the first thing every new install sees, so the stub ships
             the empty state rather than a bare header row over nothing. --}}
        <x-wirekit::empty-state
            icon="globe"
            variant="outline"
            :title="__('webhooks::management.empty.no_subscriptions.title')"
            :description="__('webhooks::management.empty.no_subscriptions.description')"
        />
    @else
        {{-- id + tabindex="-1" so the delete dialog below has somewhere to put the focus back.
             tabindex="-1" is the half that gets left off: without it a target cannot take focus
             programmatically, and focus-return-to then points at an element that refuses it —
             which behaves exactly like declaring nothing at all. --}}
        <x-wirekit::table
            id="wh-subscriptions-table"
            tabindex="-1"
            hoverable
            :aria-label="__('webhooks::management.a11y.subscriptions_table')"
            :table-label="__('webhooks::management.a11y.subscriptions_table')"
        >
            <x-wirekit::table.head>
                <x-wirekit::table.row>
                    <x-wirekit::table.th>{{ __('webhooks::management.table.endpoint') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::management.table.events') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::management.table.status') }}</x-wirekit::table.th>
                    {{-- The actions column carries no visible header, but it still needs an
                         accessible name: an empty th announces nothing to a screen reader. --}}
                    <x-wirekit::table.th align="right">
                        <x-wirekit::visually-hidden>{{ __('webhooks::management.table.actions') }}</x-wirekit::visually-hidden>
                    </x-wirekit::table.th>
                </x-wirekit::table.row>
            </x-wirekit::table.head>
            <x-wirekit::table.body>
                @foreach ($subscriptions as $subscription)
                    <x-wirekit::table.row wire:key="sub-{{ $subscription->id }}">
                        <x-wirekit::table.th headerScope="row">
                            <x-wirekit::stack gap="none">
                                <x-wirekit::text weight="medium">{{ $subscription->name ?? '—' }}</x-wirekit::text>
                                <x-wirekit::text size="sm" intent="muted">{{ $subscription->url }}</x-wirekit::text>
                            </x-wirekit::stack>
                        </x-wirekit::table.th>
                        <x-wirekit::table.td>{{ implode(', ', $subscription->eventTypeNames()) }}</x-wirekit::table.td>
                        {{-- Switched OFF outranks every health band: an endpoint nobody is
                             delivering to has no current health, and reporting a stale one as
                             if it were live is the same confusion in the other direction.
                             Everything below that mirrors the self-service health matrix —
                             same bands, same intents, same words — so one endpoint does not
                             read differently depending on which screen you opened. An unknown
                             band (no recent history yet) stays Active: a freshly registered
                             endpoint is not a problem, and coloring it as one trains the
                             reader to ignore the color. --}}
                        @php
                            // ONE ladder producing both halves, not two ladders in step. Written
                            // as two, the intent and the label can disagree — a red badge saying
                            // "Disabled", or "Failing" in success green — and that is not
                            // hypothetical: the first version here WAS two ladders, and the
                            // mutant that dropped the off-arm from only one of them survived the
                            // arm that was supposed to catch it.
                            [$healthIntent, $healthLabel] = match (true) {
                                ! $subscription->is_active => ['neutral', __('webhooks::management.subscription.disabled')],
                                $subscription->health_status === 'failing' => ['danger', __('webhooks::management.subscription.failing')],
                                $subscription->health_status === 'degraded' => ['warning', __('webhooks::management.subscription.degraded')],
                                default => ['success', __('webhooks::management.subscription.active')],
                            };
                        @endphp
                        <x-wirekit::table.td>
                            <x-wirekit::stack gap="none">
                                <x-wirekit::badge :intent="$healthIntent">
                                    {{ $healthLabel }}
                                </x-wirekit::badge>
                                {{-- "Disabled" has two causes that look identical on screen, and
                                     they call for opposite actions: flip the switch back, or go
                                     fix the destination. Named here rather than left to be
                                     guessed. --}}
                                @if ($subscription->wasAutoDisabled())
                                    <x-wirekit::text size="sm" intent="muted">
                                        {{ __('webhooks::management.subscription.auto_disabled', ['count' => $subscription->consecutive_failures]) }}
                                    </x-wirekit::text>
                                @endif
                            </x-wirekit::stack>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            <x-wirekit::button
                                size="sm"
                                surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}"
                                wire:click="edit({{ $subscription->id }})"
                                :aria-label="__('webhooks::management.a11y.edit_subscription', ['url' => $subscription->url])"
                            >{{ __('webhooks::management.subscription.edit') }}</x-wirekit::button>

                            {{-- Named per row like its neighbors. The visible word switches with
                                 the row's state, so it is interpolated rather than described --
                                 the name then contains whichever word is on the button. --}}
                            @php($toggleLabel = $subscription->is_active ? __('webhooks::management.subscription.disable') : __('webhooks::management.subscription.enable'))
                            <x-wirekit::button size="sm" surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}" wire:click="toggle({{ $subscription->id }})" wire:loading.attr="disabled" wire:target="toggle" :aria-label="__('webhooks::management.a11y.toggle_subscription', ['label' => $toggleLabel, 'url' => $subscription->url])">
                                {{ $toggleLabel }}
                            </x-wirekit::button>

                            {{-- Rotating starts a clock on the old secret rather than invalidating
                                 it, but it is still a change every consumer of this endpoint has to
                                 follow — so it is confirmed through the same alert-dialog as the
                                 destructive action beside it, never a bare click. --}}
                            <x-wirekit::alert-dialog :name="'rotate-subscription-' . $subscription->id">
                                <x-slot:trigger>
                                    <x-wirekit::button
                                        size="sm"
                                        surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}"
                                        :aria-label="__('webhooks::management.a11y.rotate_subscription', ['label' => __('webhooks::management.subscription.rotate'), 'url' => $subscription->url])"
                                    >{{ __('webhooks::management.subscription.rotate') }}</x-wirekit::button>
                                </x-slot:trigger>

                                <x-wirekit::alert-dialog.title>{{ __('webhooks::management.rotate_dialog.title') }}</x-wirekit::alert-dialog.title>
                                <x-wirekit::alert-dialog.description>
                                    {{ __('webhooks::management.rotate_dialog.description') }}
                                </x-wirekit::alert-dialog.description>
                                <x-wirekit::alert-dialog.actions>
                                    <x-wirekit::alert-dialog.cancel />
                                    <x-wirekit::button
                                        wire:click="rotate({{ $subscription->id }})"
                                    >{{ __('webhooks::management.rotate_dialog.confirm') }}</x-wirekit::button>
                                </x-wirekit::alert-dialog.actions>
                            </x-wirekit::alert-dialog>

                            {{-- Deleting an endpoint is irreversible and stops a live production
                                 integration, so it is confirmed through the WireKit alert-dialog —
                                 never a bare one-click destroy, and never wire:confirm. This is the
                                 pattern to copy when you restyle the stub.

                                 And it is the one dialog here that needs focus-return-to. A dialog
                                 normally hands focus back to its trigger; after a delete the
                                 trigger is gone with the row, so focus falls to <body> and a
                                 keyboard or screen-reader user is returned to the top of the
                                 document with no announcement that the irreversible thing they just
                                 confirmed happened. The target is the table — it survives, and it
                                 is where the removed row was. A selector pointing into the host's
                                 surrounding chrome would break the moment the host changes its
                                 markup, and break silently.

                                 The rotate dialog beside it deliberately has NONE: its row
                                 survives, so focus returns to the trigger by itself, and
                                 declaring a target there would REPLACE that with a jump to the
                                 table — worse than doing nothing. --}}
                            <x-wirekit::alert-dialog
                                :name="'delete-subscription-' . $subscription->id"
                                focus-return-to="#wh-subscriptions-table"
                            >
                                <x-slot:trigger>
                                    <x-wirekit::button
                                        size="sm"
                                        surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}"
                                        intent="danger"
                                        :aria-label="__('webhooks::management.a11y.delete_subscription', ['url' => $subscription->url])"
                                    >{{ __('webhooks::management.subscription.delete') }}</x-wirekit::button>
                                </x-slot:trigger>

                                <x-wirekit::alert-dialog.title>{{ __('webhooks::management.delete_dialog.title') }}</x-wirekit::alert-dialog.title>
                                <x-wirekit::alert-dialog.description>
                                    {{ __('webhooks::management.delete_dialog.description') }}
                                </x-wirekit::alert-dialog.description>
                                <x-wirekit::alert-dialog.actions>
                                    {{-- No cancel label passed on purpose: WireKit's own default is `__('Cancel')`,
                                                 and from 2.27 it ships all seven locales this package ships. composer.json
                                                 refuses anything older, so that is a guarantee rather than a hope — passing
                                                 our own label again would be a second answer to a question the library
                                                 already answers, and the second answer is the one that drifts. --}}
                                            <x-wirekit::alert-dialog.cancel />
                                    <x-wirekit::button
                                        intent="danger"
                                        wire:click="destroy({{ $subscription->id }})"
                                    >{{ __('webhooks::management.delete_dialog.confirm') }}</x-wirekit::button>
                                </x-wirekit::alert-dialog.actions>
                            </x-wirekit::alert-dialog>
                        </x-wirekit::table.td>
                    </x-wirekit::table.row>
                @endforeach
            </x-wirekit::table.body>
        </x-wirekit::table>
    @endif
    {{-- OUTSIDE the @if, matching the neutral stub beside this one. A simplePaginate list
         does not know how many pages there are, so a page past the end renders an empty
         table — and with the control inside the @else the only way back was to edit the
         URL. The package's pagination view renders nothing while there is a single page,
         so putting it here costs nothing in the ordinary case. --}}
    {{ $subscriptions->links() }}
</x-wirekit::stack>
