{{-- The tenant's own endpoint list: URL, cached health badge, event-type summary, an
     active toggle button (aria-pressed) and the per-row test / reveal-secret / edit /
     delete actions. Always owner-scoped, paginated, with a WireKit empty state. Deletion
     uses the WireKit alert-dialog (never wire:confirm) and is hidden when disallowed. --}}
{{-- Read once for the whole table rather than per row: a fifty-row page would otherwise ask
     the config repository two hundred and fifty times for an answer that cannot change inside
     one render. An entry set to null draws that action without an icon, and a host that empties
     the whole key gets the label-only row this list had before 3.4.0.

     Each leaf is named in full rather than pulled out of the parent array, because every shipped
     key is public API under SemVer and the tree audit holds each one to being genuinely read --
     a key reachable only as part of a bulk fetch reads, from the outside, exactly like a key
     nothing reads at all. --}}
@php($rowActionIcons = [
    'ping' => config('webhooks.ui.row_action_icons.ping'),
    'secret' => config('webhooks.ui.row_action_icons.secret'),
    'edit' => config('webhooks.ui.row_action_icons.edit'),
    'transform' => config('webhooks.ui.row_action_icons.transform'),
    'delete' => config('webhooks.ui.row_action_icons.delete'),
    {{-- A published config from before this key has no entry for it, and there the confirmation
         follows the row action, so one deletion keeps one symbol. A present null still drops it. --}}
    'delete_confirm' => config('webhooks.ui.row_action_icons.delete_confirm', config('webhooks.ui.row_action_icons.delete')),
])
<div class="wh-portal-list" wire:key="endpoint-list">
    <div class="mb-[var(--space-wk-sm)] flex flex-wrap items-center justify-between gap-[var(--padding-wk-x-md)]">
        <x-wirekit::heading :level="2" size="md">{{ __('webhooks::self-service.list.heading') }}</x-wirekit::heading>

        @if ($this->capReached)
            <x-wirekit::text size="sm" intent="muted">{{ __('webhooks::self-service.list.cap_reached') }}</x-wirekit::text>
        @else
            <x-wirekit::button wire:click="newEndpoint" wire:loading.attr="disabled" wire:target="newEndpoint">
                <x-slot:iconLeft><x-wirekit::icon name="plus" size="sm" /></x-slot:iconLeft>
                {{ __('webhooks::self-service.list.new_endpoint') }}
            </x-wirekit::button>
        @endif
    </div>

    @if ($endpoints->isEmpty())
        {{-- Outlined: this empty state stands on its own in the page body, not inside a
             card, so it needs its own chrome to read as a placeholder. --}}
        <x-wirekit::empty-state
            icon="globe"
            variant="outline"
            :title="__('webhooks::self-service.empty.no_endpoints.title')"
            :description="__('webhooks::self-service.empty.no_endpoints.description')"
        />
    @else
        {{-- The focus target of the delete dialog below. Both halves are needed: without
             tabindex="-1" the table cannot take focus programmatically, and focus-return-to
             then points at an element that refuses it — which behaves exactly like declaring
             nothing at all. --}}
        <x-wirekit::table
            id="wh-portal-endpoints-table"
            tabindex="-1"
            hoverable
            :aria-label="__('webhooks::self-service.a11y.endpoints_table')"
            :table-label="__('webhooks::self-service.a11y.endpoints_table')"
        >
            <x-wirekit::table.head>
                <x-wirekit::table.row>
                    {{-- The floor is on the column that identifies the row, and only on that one.
                         The table scrolls sideways inside its own container on a narrow screen,
                         which is the right answer for a table this wide -- but a column free to
                         collapse takes the squeeze instead, and this is the column carrying the
                         one string a tenant reads to tell two rows apart. At 375px it reached
                         92px, and a real URL under `break-all` wrapped into a dozen short lines
                         until the row stood 359px tall, nearly all of it whitespace beside the
                         health badge. Widening the table costs a sideways scroll the reader
                         already has; collapsing this column costs them the row. --}}
                    <x-wirekit::table.th class="min-w-64">{{ __('webhooks::self-service.table.endpoint') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.table.health') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.table.events') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.table.status') }}</x-wirekit::table.th>
                    <x-wirekit::table.th align="right">{{ __('webhooks::self-service.table.actions') }}</x-wirekit::table.th>
                </x-wirekit::table.row>
            </x-wirekit::table.head>
            <x-wirekit::table.body>
                @foreach ($endpoints as $endpoint)
                    @php($healthIntent = match ($endpoint->health_status) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'failing' => 'danger',
                        default => 'neutral',
                    })
                    {{-- The badge label is translated for display; the key is the stored
                         health_status value, which never changes. --}}
                    @php($healthLabel = __('webhooks::self-service.health.'.($endpoint->health_status ?? 'unknown')))
                    <x-wirekit::table.row wire:key="ep-{{ $endpoint->id }}">
                        <x-wirekit::table.th headerScope="row" class="min-w-64">
                            <x-wirekit::stack gap="none">
                                @if ($endpoint->name !== null)
                                    <x-wirekit::text weight="medium">{{ $endpoint->name }}</x-wirekit::text>
                                @endif
                                <x-wirekit::text size="sm" intent="muted" class="break-all">{{ $endpoint->url }}</x-wirekit::text>
                            </x-wirekit::stack>
                        </x-wirekit::table.th>
                        <x-wirekit::table.td>
                            <x-wirekit::badge :intent="$healthIntent">
                                {{ $healthLabel }}@if ($endpoint->health_score !== null) · {{ $endpoint->health_score }}@endif
                            </x-wirekit::badge>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>
                            <x-wirekit::text size="sm">{{ implode(', ', $endpoint->eventTypeNames()) ?: '—' }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>
                            <x-wirekit::button
                                size="sm"
                                :surface="$endpoint->is_active ? 'filled' : 'ghost'"
                                :intent="$endpoint->is_active ? 'success' : 'neutral'"
                                wire:click="toggle({{ $endpoint->id }})"
                                :aria-pressed="$endpoint->is_active ? 'true' : 'false'"
                                :aria-label="__('webhooks::self-service.a11y.toggle_active', [
                                    // The visible word is interpolated rather than described,
                                    // so the accessible name CONTAINS it verbatim in all seven
                                    // languages (WCAG 2.5.3) and cannot drift when one of them
                                    // is retranslated. Voice control matches what is on screen.
                                    'state' => $endpoint->is_active ? __('webhooks::self-service.list.active') : __('webhooks::self-service.list.disabled'),
                                    'url' => $endpoint->url,
                                ])"
                            >{{ $endpoint->is_active ? __('webhooks::self-service.list.active') : __('webhooks::self-service.list.disabled') }}</x-wirekit::button>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            <div class="inline-flex items-center gap-[var(--gap-wk-sm)]">
                                {{-- First, and the order is the point: this is the action a
                                     tenant reaches for most and the only one that costs
                                     nothing. Destructive stays last. It carries a loading
                                     state the neighbors do not, because it is the only one
                                     that waits on a network round trip. --}}
                                <x-wirekit::button
                                    size="sm"
                                    surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}"
                                    wire:click="ping({{ $endpoint->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="ping"
                                    :aria-label="__('webhooks::self-service.a11y.ping_endpoint', ['label' => __('webhooks::self-service.list.ping'), 'url' => $endpoint->url])"
                                >
                                    @if (($rowActionIcons['ping'] ?? null) !== null)
                                        <x-slot:iconLeft><x-wirekit::icon :name="$rowActionIcons['ping']" size="sm" /></x-slot:iconLeft>
                                    @endif
                                    {{ __('webhooks::self-service.list.ping') }}
                                </x-wirekit::button>
                                <x-wirekit::button
                                    size="sm"
                                    surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}"
                                    wire:click="reveal({{ $endpoint->id }})"
                                    :aria-label="__('webhooks::self-service.a11y.reveal_secret', ['url' => $endpoint->url])"
                                >
                                    @if (($rowActionIcons['secret'] ?? null) !== null)
                                        <x-slot:iconLeft><x-wirekit::icon :name="$rowActionIcons['secret']" size="sm" /></x-slot:iconLeft>
                                    @endif
                                    {{ __('webhooks::self-service.list.secret') }}
                                </x-wirekit::button>
                                <x-wirekit::button
                                    size="sm"
                                    surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}"
                                    wire:click="edit({{ $endpoint->id }})"
                                    :aria-label="__('webhooks::self-service.a11y.edit_endpoint', ['url' => $endpoint->url])"
                                >
                                    @if (($rowActionIcons['edit'] ?? null) !== null)
                                        <x-slot:iconLeft><x-wirekit::icon :name="$rowActionIcons['edit']" size="sm" /></x-slot:iconLeft>
                                    @endif
                                    {{ __('webhooks::self-service.list.edit') }}
                                </x-wirekit::button>
                                {{-- Only when the editor is actually mounted. This panel is
                                     embeddable in a host's own screen, where the portal's
                                     routes need not exist — and this link is drawn per ROW,
                                     so an unconditional one renders fine against an empty
                                     account and throws on the first real endpoint. --}}
                                @if ($showTransformLink)
                                    <x-wirekit::button
                                        size="sm"
                                        surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}"
                                        :href="route('webhooks.self-service.transform', $endpoint->id)"
                                        wire:navigate
                                        :aria-label="__('webhooks::self-service.a11y.edit_transform', ['label' => __('webhooks::self-service.list.transform'), 'url' => $endpoint->url])"
                                    >
                                        @if (($rowActionIcons['transform'] ?? null) !== null)
                                            <x-slot:iconLeft><x-wirekit::icon :name="$rowActionIcons['transform']" size="sm" /></x-slot:iconLeft>
                                        @endif
                                        {{ __('webhooks::self-service.list.transform') }}
                                    </x-wirekit::button>
                                @endif

                                @if ($allowDelete)
                                    {{-- focus-return-to, because this is the dialog whose trigger
                                         does not survive its own action. A dialog normally hands
                                         focus back to the button that opened it; destroy() removes
                                         the row and calls resetPage(), so that button is gone and
                                         focus falls to <body> — a keyboard or screen-reader user is
                                         returned to the top of the document right after confirming
                                         something irreversible. The table survives and is where the
                                         row was.

                                         The operator stub beside this one already solves it and
                                         explains why; this is the surface that ships to tenants and
                                         needs no publishing, so it is the one that had to be right
                                         first. Note the counter-case there: the rotate dialog
                                         deliberately declares no target, because its row survives
                                         and focus returns to the trigger by itself. --}}
                                    <x-wirekit::alert-dialog
                                        :name="'delete-endpoint-' . $endpoint->id"
                                        focus-return-to="#wh-portal-endpoints-table"
                                    >
                                        <x-slot:trigger>
                                            <x-wirekit::button
                                                size="sm"
                                                surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}"
                                                intent="danger"
                                                :aria-label="__('webhooks::self-service.a11y.delete_endpoint', ['url' => $endpoint->url])"
                                            >
                                                @if (($rowActionIcons['delete'] ?? null) !== null)
                                                    <x-slot:iconLeft><x-wirekit::icon :name="$rowActionIcons['delete']" size="sm" /></x-slot:iconLeft>
                                                @endif
                                                {{ __('webhooks::self-service.list.delete') }}
                                            </x-wirekit::button>
                                        </x-slot:trigger>

                                        <x-wirekit::alert-dialog.title>{{ __('webhooks::self-service.delete_dialog.title') }}</x-wirekit::alert-dialog.title>
                                        <x-wirekit::alert-dialog.description>
                                            {{ __('webhooks::self-service.delete_dialog.description') }}
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
                                                wire:click="destroy({{ $endpoint->id }})"
                                            >
                                                @if (($rowActionIcons['delete_confirm'] ?? null) !== null)
                                                    <x-slot:iconLeft><x-wirekit::icon :name="$rowActionIcons['delete_confirm']" size="sm" /></x-slot:iconLeft>
                                                @endif
                                                {{ __('webhooks::self-service.delete_dialog.confirm') }}
                                            </x-wirekit::button>
                                        </x-wirekit::alert-dialog.actions>
                                    </x-wirekit::alert-dialog>
                                @endif
                            </div>
                        </x-wirekit::table.td>
                    </x-wirekit::table.row>
                @endforeach
            </x-wirekit::table.body>
        </x-wirekit::table>

        <div class="mt-[var(--space-wk-sm)]">
            {{ $endpoints->links() }}
        </div>
    @endif
</div>
