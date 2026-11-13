{{-- The tenant's own delivery log: event, outcome badge, response code and when, newest
     first, bounded to a time window, optionally narrowed to one endpoint, with a replay
     action. Owner-scoped on the delivery row's own morph columns. Never shows the outbound
     payload; shows the stored error only where the host switched it on, since that text can
     quote back whatever the receiver wrote. --}}
<div class="wh-portal-deliveries" wire:key="endpoint-deliveries">

    {{-- Permanently in the DOM, so a filter change has something to speak through: a region
         inserted together with its own text is not announced by most screen readers. The
         wire:key carries the count, so Livewire's morph sees a changed node rather than an
         identical one it can leave alone -- the same detail the transform editor's preview
         region already depends on.

         A live-bound filter swaps the table underneath and says nothing on its own. The count
         is in the document, in the pagination summary, but as a plain paragraph outside any
         live region: a reader has to travel back down and read to learn whether the change
         produced three rows, two hundred, or none. And the empty state replaces the table, so a
         virtual cursor that was standing in it loses its position silently (WCAG 4.1.3). --}}
    <x-wirekit::visually-hidden role="status" aria-live="polite" wire:key="wh-filtered-{{ $deliveries->total() }}">{{ trans_choice('webhooks::pagination.filtered', $deliveries->total()) }}</x-wirekit::visually-hidden>
    <div class="mb-[var(--space-wk-sm)] flex flex-wrap items-center justify-between gap-[var(--padding-wk-x-md)]">
        <x-wirekit::heading :level="2" size="md">{{ __('webhooks::self-service.deliveries.heading') }}</x-wirekit::heading>

        <div class="grid w-full gap-[var(--padding-wk-x-md)] sm:flex sm:w-auto sm:flex-wrap sm:items-start">
            {{-- The two lists pair up on a phone and dissolve into the row from `sm` up. --}}
            <div class="grid grid-cols-2 items-start gap-[var(--padding-wk-x-md)] sm:contents">
            @if ($windowChoices !== [])
                {{-- Both filters are WireKit's own select. On WireKit before 2.37, a select bound
                     with wire:model.live on this page left the endpoint list's delete confirmation
                     unclickable: the dialog's overlay took its geometry only from Tailwind utilities
                     the host build had to compile, so the dialog sat in normal document flow and its
                     confirm button was below the fold. The package's `conflict` on WireKit keeps
                     those versions out. --}}
                <x-wirekit::select
                    name="windowDays"
                    wire:model.live="windowDays"
                    :label="__('webhooks::self-service.deliveries.window_label')"
                >
                    @foreach ($windowChoices as $choice)
                        <option value="{{ $choice }}">{{ __('webhooks::self-service.deliveries.window_days', ['days' => $choice]) }}</option>
                    @endforeach
                </x-wirekit::select>
            @endif

                {{-- The outcome filter. Its options are the enum's cases, handed in by the
                     component, rather than five literal options like the three older consoles
                     carry: those had to be edited in three files when `refused` was added, and
                     two of them were missed — a reader could see a refused delivery in the table
                     and had no way to ask for them, while the translation sat unused in all
                     seven locales. A loop over the case set cannot fall behind the enum. --}}
                <x-wirekit::select
                    name="status"
                    wire:model.live="status"
                    :label="__('webhooks::self-service.deliveries.status_label')"
                >
                    <option value="">{{ __('webhooks::self-service.deliveries.all_statuses') }}</option>
                    @foreach ($statusChoices as $choice)
                        <option value="{{ $choice->value }}">{{ __('webhooks::self-service.deliveries.status.'.$choice->value) }}</option>
                    @endforeach
                </x-wirekit::select>
            </div>

            {{-- Two datetime pickers, so a bound is a moment: a day picked alone takes its first
                 minute for From and its last for Until, and a time from the list narrows it to the
                 minute. Each is handed what the property holds through FilterBound::shown(), since
                 a link or a host may still pass a day, which the picker cannot show. The pair
                 narrows within the window above and can never reach past it — the window's own
                 bound stays on the query, so the older of the two bounds simply loses.

                 Every filter of the row shows its label, the lists as well as the two pickers,
                 and the row lines them up at the top: with a label above each control the controls
                 stand on one line, whatever room a picker keeps below its own. On a phone each
                 picker takes a row of its own, and from `sm` up each keeps a width that holds a
                 moment, its clear button and its chevron. --}}
            <div class="grid grid-cols-1 items-start gap-[var(--padding-wk-x-md)] sm:contents">
                <div class="min-w-0 sm:w-72">
                    <x-wirekit::datetime-picker
                        name="from"
                        :value="\Pushery\Webhooks\Support\FilterBound::shown($from, false)"
                        wire:model.live="from"
                        default-time="00:00"
                        clearable
                        :label="__('webhooks::self-service.deliveries.from')"
                    />
                </div>
                <div class="min-w-0 sm:w-72">
                    <x-wirekit::datetime-picker
                        name="until"
                        :value="\Pushery\Webhooks\Support\FilterBound::shown($until, true)"
                        wire:model.live="until"
                        default-time="23:59"
                        clearable
                        :label="__('webhooks::self-service.deliveries.until')"
                    />
                </div>
            </div>

        @if ($endpoints->isNotEmpty())
            <x-wirekit::select
                name="endpointId"
                wire:model.live="endpointId"
                :label="__('webhooks::self-service.deliveries.filter_label')"
            >
                <option value="">{{ __('webhooks::self-service.deliveries.all_endpoints') }}</option>
                @foreach ($endpoints as $endpoint)
                    <option value="{{ $endpoint->id }}">{{ $endpoint->name ?? $endpoint->url }}</option>
                @endforeach
            </x-wirekit::select>

            @if ($endpointsTruncated)
                {{-- Said out loud rather than truncated in silence: a list that looks complete
                     is how a reader concludes an endpoint has no deliveries when it was simply
                     never offered. --}}
                <x-wirekit::text size="sm" intent="muted">{{ __('webhooks::self-service.deliveries.endpoints_truncated') }}</x-wirekit::text>
            @endif
        @endif
        </div>
    </div>

    @if ($message !== '')
        <x-wirekit::text size="sm" role="status" class="mb-[var(--space-wk-sm)]">{{ $message }}</x-wirekit::text>
    @endif

    {{-- Keyed on the total, not on this page being empty. A page past the end — which a
         reader reaches by paging and then having the retention window drop the tail — would
         otherwise render "nothing has been sent" together with no pagination control at all,
         and a reader who is one click from their history would be told they have none. --}}
    @if ($deliveries->total() === 0)
        {{-- Two descriptions, because the unfiltered one is a claim about every endpoint the
             reader owns and would be false while a filter is on. The unfiltered one names the
             retention window on purpose: after it there provably are no rows by design, and
             "nothing yet" would mislead about the very question this panel exists to answer. --}}
        <x-wirekit::empty-state
            icon="inbox"
            variant="outline"
            :title="__('webhooks::self-service.empty.no_deliveries.title')"
            :description="__('webhooks::self-service.empty.no_deliveries.'.$emptyStateKey)"
        />
    @else
        {{-- Below sm each delivery stands as a card and each value as a line that names its column, as
             the endpoint list and the health board do: as a table its seven columns needed a sideways
             scroll on a phone. --}}
        <x-wirekit::table
            id="wh-portal-deliveries-table"
            hoverable
            stack-below="sm"
            :aria-label="__('webhooks::self-service.a11y.deliveries_table')"
            :table-label="__('webhooks::self-service.a11y.deliveries_table')"
        >
            <x-wirekit::table.head>
                <x-wirekit::table.row>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.deliveries.event') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.deliveries.outcome') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.deliveries.response_code') }}</x-wirekit::table.th>
                    <x-wirekit::table.th align="right">{{ __('webhooks::self-service.deliveries.attempts') }}</x-wirekit::table.th>
                    @if ($showsErrors)
                        {{-- A floor on this column from sm up, where the deliveries stand as a table,
                             for the reason the endpoint list gives its URL one: a column free to
                             collapse takes the squeeze. This one carries the text the reader came
                             for, and in a narrow table it got 80px, one word to a line. --}}
                        <x-wirekit::table.th class="sm:min-w-64">{{ __('webhooks::self-service.deliveries.error') }}</x-wirekit::table.th>
                    @endif
                    <x-wirekit::table.th align="right">{{ __('webhooks::self-service.deliveries.when') }}</x-wirekit::table.th>
                    <x-wirekit::table.th align="right"><span class="sr-only">{{ __('webhooks::self-service.deliveries.replay') }}</span></x-wirekit::table.th>
                </x-wirekit::table.row>
            </x-wirekit::table.head>
            <x-wirekit::table.body>
                @foreach ($deliveries as $delivery)
                    {{-- The same mapping the operator dashboard uses: exhausted is danger,
                         not warning. Two surfaces disagreeing about which outcome is grave
                         is a difference a reader would have to learn. --}}
                    @php($intent = $delivery->status->intent())
                    {{-- In the display zone of `webhooks.dashboard.timezone`, which a host resolves per
                         reader, so the time reads as every other time of the application. --}}
                    @php($when = \Pushery\Webhooks\Dashboard\DashboardTimezone::apply($delivery->created_at)->settings(['locale' => app()->getLocale()]))
                    <x-wirekit::table.row wire:key="d-{{ $delivery->id }}">
                        {{-- The row header is the event, not the outcome badge: announcing
                             "Failed" tells a screen-reader user nothing about which
                             delivery failed. --}}
                        <x-wirekit::table.th headerScope="row">
                            <x-wirekit::text weight="medium">{{ $delivery->event_type }}</x-wirekit::text>
                        </x-wirekit::table.th>
                        <x-wirekit::table.td :label="__('webhooks::self-service.deliveries.outcome')">
                            <x-wirekit::badge :intent="$intent">{{ __('webhooks::self-service.deliveries.status.'.$delivery->status->value) }}</x-wirekit::badge>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td :label="__('webhooks::self-service.deliveries.response_code')">
                            {{-- Null whenever no HTTP answer arrived at all, which is not
                                 the same as a zero. --}}
                            {{-- The duration hangs off the code rather than standing alone: the
                                 package measures it on every attempt, including the ones that
                                 never got an answer, and printing that number by itself would
                                 read as "the receiver took 30s" when it means "we waited 30s for
                                 nothing". It is the question that comes after the status code --
                                 it arrived, so why did it take that long -- and a receiver
                                 getting slower is the run-up to one that fails.

                                 One expression rather than a raw PHP block, and the reason is
                                 not style. Blade lifts raw blocks out of the template before it
                                 strips comments, matching non-greedily from the first opening
                                 directive -- so a block form anywhere below the inline ones this
                                 file already uses swallows everything between them, and the rest
                                 of the template ships uncompiled. Naming those two directives in
                                 prose here does it too, which is why this paragraph does not. --}}
                            <x-wirekit::text size="sm" intent="muted">{{ $delivery->response_code === null ? '—' : $delivery->response_code.($delivery->duration_ms === null ? '' : ' · '.__('webhooks::self-service.deliveries.duration', ['ms' => \Pushery\Webhooks\Support\LocalizedNumber::format($delivery->duration_ms)])) }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        {{-- How often the package has sent this one, so a reader tells a delivery that already went
                             out again from one that went out once before deciding to send it again. --}}
                        {{-- Its own label keeps the figure a named line of the card below sm: a cell aligned to
                             the end without one stands as the card's row of actions. --}}
                        <x-wirekit::table.td align="right" :label="__('webhooks::self-service.deliveries.attempts')">
                            <x-wirekit::text size="sm" intent="muted">{{ \Pushery\Webhooks\Support\LocalizedNumber::format($delivery->attempt) }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        @if ($showsErrors)
                            {{-- Blade escapes it, so markup a receiver wrote back is text. --}}
                            <x-wirekit::table.td :label="__('webhooks::self-service.deliveries.error')">
                                <x-wirekit::text size="sm" intent="muted">{{ $delivery->error ?? '—' }}</x-wirekit::text>
                            </x-wirekit::table.td>
                        @endif
                        <x-wirekit::table.td align="right" :label="__('webhooks::self-service.deliveries.when')">
                            <time datetime="{{ $delivery->created_at->toIso8601String() }}" title="{{ $when->isoFormat(__('webhooks::self-service.formats.moment')) }}" class="whitespace-nowrap">
                                <x-wirekit::text size="sm" intent="muted">{{ $when->diffForHumans() }}</x-wirekit::text>
                            </time>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            {{-- Named for the row it belongs to. Twenty buttons all called
                                 "Send again" leave a screen-reader user picking at random,
                                 and this one sends an HTTP request when picked wrong. --}}
                            {{-- Asked once before it goes, as the operator console asks: the
                                 delivery reaches the customer's receiver again, under its
                                 original id, and the wrong row is one click away in a list of
                                 identical buttons. The button opens the dialog and carries no
                                 action of its own; only the confirmation sends. It closes with
                                 the re-render its action causes, and says so, so an application
                                 that turns close-on-confirm on for every dialog does not ask
                                 this one for a confirm control. --}}
                            <x-wirekit::alert-dialog :name="'replay-'.$delivery->id" :close-on-confirm="false">
                                <x-slot:trigger>
                                    <x-wirekit::button
                                        size="sm"
                                        surface="{{ config('webhooks.ui.secondary_surface', 'filled') }}" intent="neutral"
                                        :aria-label="__('webhooks::self-service.deliveries.replay_sr', ['label' => __('webhooks::self-service.deliveries.replay'), 'event' => $delivery->event_type, 'at' => $when->isoFormat(__('webhooks::self-service.formats.precise'))])"
                                    >{{ __('webhooks::self-service.deliveries.replay') }}</x-wirekit::button>
                                </x-slot:trigger>

                                <x-wirekit::alert-dialog.title>{{ __('webhooks::self-service.deliveries.replay_dialog.title') }}</x-wirekit::alert-dialog.title>
                                <x-wirekit::alert-dialog.description>{{ __('webhooks::self-service.deliveries.replay_dialog.description') }}</x-wirekit::alert-dialog.description>
                                <x-wirekit::alert-dialog.actions>
                                    <x-wirekit::alert-dialog.cancel />
                                    {{-- Scoped like every sibling replay button. Without it the
                                         row grays out on any commit of this component, a filter
                                         change included. --}}
                                    <x-wirekit::button wire:click="redeliver('{{ $delivery->id }}')" wire:loading.attr="disabled" wire:target="redeliver">{{ __('webhooks::self-service.deliveries.replay_dialog.confirm') }}</x-wirekit::button>
                                </x-wirekit::alert-dialog.actions>
                            </x-wirekit::alert-dialog>
                        </x-wirekit::table.td>
                    </x-wirekit::table.row>
                @endforeach
            </x-wirekit::table.body>
        </x-wirekit::table>

        <div class="mt-[var(--space-wk-sm)]">
            {{-- Its own landmark name: the endpoint list on this same page carries a
                 paginator too, and two navigation landmarks called "Pagination" leave a
                 screen-reader user picking between them at random. --}}
            {{-- One page on each side of the current one: the default three made a row of twelve numbers that
                 outgrew the table it pages. --}}
            {{ $deliveries->onEachSide(1)->links(data: ['landmarkLabel' => __('webhooks::self-service.deliveries.pagination_label')]) }}
        </div>
    @endif
</div>
