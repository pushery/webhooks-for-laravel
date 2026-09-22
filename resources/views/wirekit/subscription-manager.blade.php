{{-- WireKit-styled stub (publish tag: webhooks-ui-wirekit). Requires pushery/wirekit
     and a Tailwind build that scans both packages' views (see the "Styling the UI" guide
     at https://docs.pushery.com/webhooks-for-laravel/guides/styling-the-ui); place behind
     your own authorization. Publish the neutral variant
     instead with the webhooks-ui tag. --}}
{{-- Pairs badly with the wirekit.delivery-log stub on the same page: its filter is a WireKit select
     bound with wire:model.live, and that combination makes the confirm action of the delete dialogs
     below unclickable. The dialog opens, the click never lands, nothing is logged. Reported
     upstream; the comment in that stub carries the detail. --}}
{{-- The row-action icons, out of the SAME config block the self-service endpoint list reads. Two
     screens of one package that disagree about whether a row action carries a symbol is a difference
     a reader notices and cannot explain -- and this screen had no seam at all, so a host who wanted
     icons here could only publish the view, which is the copy the other screen abolished.

     Each leaf is named in full rather than pulled out of the parent array, for the reason the
     endpoint list gives: every shipped key is public API under SemVer, and a key reachable only
     through a bulk fetch reads, from the outside, exactly like a key nothing reads at all.

     `null` still means NO icon, so a host who wants text-only actions can say so.

     The BLOCK form below is deliberate, and the reason is a trap rather than a preference. Blade
     lifts php blocks out of the file BEFORE it compiles anything else, using one lazy pattern that
     pairs an opening marker with the next closing one ANYWHERE further down. The bracketed
     one-expression form of that directive carries no closing marker of its own, so it pairs with
     the next block's closer instead and swallows everything between the two: that whole region is
     stored as raw PHP, the markup inside it is never compiled, and the view dies on the first tag
     it meets there -- a parse error naming an attribute, pointing at a line nobody edited.

     So this file keeps no bracketed form, and neither marker is spelled out in any comment here:
     the lift runs before comments are removed, so naming one in prose arms it. Described on
     purpose, which is cheaper than the three attempts it took to find. --}}
@php
    $rowActionIcons = [
        'edit' => config('webhooks.ui.row_action_icons.edit'),
        'enable' => config('webhooks.ui.row_action_icons.enable'),
        'disable' => config('webhooks.ui.row_action_icons.disable'),
        'rotate_secret' => config('webhooks.ui.row_action_icons.rotate_secret'),
        // A config published before these keys existed has no entry for the confirmation, and there
        // it follows the row action, so one rotation keeps one symbol. A present null still drops it.
        'rotate_secret_confirm' => config('webhooks.ui.row_action_icons.rotate_secret_confirm', config('webhooks.ui.row_action_icons.rotate_secret')),
        'delete' => config('webhooks.ui.row_action_icons.delete'),
        'delete_confirm' => config('webhooks.ui.row_action_icons.delete_confirm', config('webhooks.ui.row_action_icons.delete')),
    ];
@endphp
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
        {{-- Level 2, not WireKit's default 3: this screen brings no heading of its own, so the title
             is the first heading under the host page's, and an h3 there skipped a level. --}}
        <x-wirekit::empty-state
            icon="globe"
            variant="outline"
            :level="2"
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
                    {{-- The floor sits on the column that identifies the row, and only on that one,
                         for the reason the self-service endpoint list already worked out: a
                         delivery URL is ONE unbroken token, so without `break-all` below it cannot
                         wrap at all and the header cell grows to the width of the longest URL,
                         pushing the columns right of it out of the table. With `break-all` and no
                         floor the opposite happens -- the column is free to collapse, and a real
                         URL becomes a dozen short lines. The two go together or neither works. --}}
                    <x-wirekit::table.th class="min-w-64">{{ __('webhooks::management.table.endpoint') }}</x-wirekit::table.th>
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
                        <x-wirekit::table.th headerScope="row" class="min-w-64">
                            <x-wirekit::stack gap="none">
                                <x-wirekit::text weight="medium">{{ $subscription->name ?? '—' }}</x-wirekit::text>
                                <x-wirekit::text size="sm" intent="muted" class="break-all">{{ $subscription->url }}</x-wirekit::text>
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
                            // One ladder produces both halves, not two ladders kept in step.
                            // Written as two, the intent and the label can disagree — a red badge
                            // saying "Disabled", or "Failing" in success green — and an arm
                            // dropped from one of them leaves the other looking correct.
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
                            {{-- FOUR buttons and, until now, no container: the cell laid them
                                 out as inline children, so the moment the column narrowed each
                                 one broke onto its own line, right-aligned and TOUCHING its
                                 neighbor -- a staircase of broken buttons rather than a group.
                                 Touching, not overlapping, which is why a rectangle-separation
                                 arm stays green over it. The gap token is what a reader sees. --}}
                            <x-wirekit::row gap="xs" class="flex-wrap justify-end">
                                <x-wirekit::button
                                    size="sm"
                                    surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}"
                                    wire:click="edit({{ $subscription->id }})"
                                    :aria-label="__('webhooks::management.a11y.edit_subscription', ['url' => $subscription->url])"
                                >
                                    @if (($rowActionIcons['edit'] ?? null) !== null)
                                        <x-slot:iconLeft><x-wirekit::icon :name="$rowActionIcons['edit']" size="sm" /></x-slot:iconLeft>
                                    @endif
                                    {{ __('webhooks::management.subscription.edit') }}
                                </x-wirekit::button>

                                {{-- Named per row like its neighbors. The visible word switches with
                                     the row's state, so it is interpolated rather than described --
                                     the name then contains whichever word is on the button.

                                     Two icon keys, not one: this button is two actions wearing one
                                     position, and its visible word already switches. A host who wants
                                     one symbol for starting and another for stopping should not have
                                     to publish the view to say so. --}}
                                @php
                                    $toggleLabel = $subscription->is_active
                                        ? __('webhooks::management.subscription.disable')
                                        : __('webhooks::management.subscription.enable');

                                    $toggleIcon = $subscription->is_active
                                        ? ($rowActionIcons['disable'] ?? null)
                                        : ($rowActionIcons['enable'] ?? null);
                                @endphp
                                <x-wirekit::button size="sm" surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}" wire:click="toggle({{ $subscription->id }})" wire:loading.attr="disabled" wire:target="toggle" :aria-label="__('webhooks::management.a11y.toggle_subscription', ['label' => $toggleLabel, 'url' => $subscription->url])">
                                    @if ($toggleIcon !== null)
                                        <x-slot:iconLeft><x-wirekit::icon :name="$toggleIcon" size="sm" /></x-slot:iconLeft>
                                    @endif
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
                                        >
                                            @if (($rowActionIcons['rotate_secret'] ?? null) !== null)
                                                <x-slot:iconLeft><x-wirekit::icon :name="$rowActionIcons['rotate_secret']" size="sm" /></x-slot:iconLeft>
                                            @endif
                                            {{ __('webhooks::management.subscription.rotate') }}
                                        </x-wirekit::button>
                                    </x-slot:trigger>

                                    <x-wirekit::alert-dialog.title>{{ __('webhooks::management.rotate_dialog.title') }}</x-wirekit::alert-dialog.title>
                                    <x-wirekit::alert-dialog.description>
                                        {{ __('webhooks::management.rotate_dialog.description') }}
                                    </x-wirekit::alert-dialog.description>
                                    <x-wirekit::alert-dialog.actions>
                                        <x-wirekit::alert-dialog.cancel />
                                        <x-wirekit::button
                                            wire:click="rotate({{ $subscription->id }})"
                                        >
                                            @if (($rowActionIcons['rotate_secret_confirm'] ?? null) !== null)
                                                <x-slot:iconLeft><x-wirekit::icon :name="$rowActionIcons['rotate_secret_confirm']" size="sm" /></x-slot:iconLeft>
                                            @endif
                                            {{ __('webhooks::management.rotate_dialog.confirm') }}
                                        </x-wirekit::button>
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
                                        >
                                            @if (($rowActionIcons['delete'] ?? null) !== null)
                                                <x-slot:iconLeft><x-wirekit::icon :name="$rowActionIcons['delete']" size="sm" /></x-slot:iconLeft>
                                            @endif
                                            {{ __('webhooks::management.subscription.delete') }}
                                        </x-wirekit::button>
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
                                        >
                                            @if (($rowActionIcons['delete_confirm'] ?? null) !== null)
                                                <x-slot:iconLeft><x-wirekit::icon :name="$rowActionIcons['delete_confirm']" size="sm" /></x-slot:iconLeft>
                                            @endif
                                            {{ __('webhooks::management.delete_dialog.confirm') }}
                                        </x-wirekit::button>
                                    </x-wirekit::alert-dialog.actions>
                                </x-wirekit::alert-dialog>
                            </x-wirekit::row>
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
