{{-- The tenant's own delivery log: event, outcome badge, response code and when, newest
     first, bounded to a time window, optionally narrowed to one endpoint, with a replay
     action. Owner-scoped on the delivery row's own morph columns. Never shows the outbound
     payload; shows the stored error only where the host switched it on, since that text can
     quote back whatever the receiver wrote. --}}
<div class="wh-portal-deliveries" wire:key="endpoint-deliveries">

    {{-- Permanently in the DOM, so a filter change has something to speak THROUGH: a region
         inserted together with its own text is not announced by most screen readers. The
         wire:key carries the count, so Livewire's morph sees a changed node rather than an
         identical one it can leave alone -- the same detail the transform editor's preview
         region already depends on.

         A live-bound filter swaps the table underneath and says nothing on its own. The count
         is in the document, in the pagination summary, but as a plain paragraph outside any
         live region: a reader has to travel back down and read to learn whether the change
         produced three rows, two hundred, or none. And the empty state REPLACES the table, so a
         virtual cursor that was standing in it loses its position silently (WCAG 4.1.3). --}}
    <x-wirekit::visually-hidden role="status" aria-live="polite" wire:key="wh-filtered-{{ $deliveries->total() }}">{{ trans_choice('webhooks::pagination.filtered', $deliveries->total()) }}</x-wirekit::visually-hidden>
    <div class="mb-[var(--padding-wk-y-md)] flex flex-wrap items-center justify-between gap-[var(--padding-wk-x-md)]">
        <x-wirekit::heading :level="2" size="md">{{ __('webhooks::self-service.deliveries.heading') }}</x-wirekit::heading>

        <div class="flex flex-wrap items-center gap-[var(--padding-wk-x-md)]">
        @if ($windowChoices !== [])
            {{-- Both filters are the library's own select again. They were native from
                 2026-08-15 to today, because a WireKit select bound with wire:model.live made
                 the endpoint list's delete confirmation unclickable on the same page — the
                 dialog opened and the click on its destructive action never landed.

                 RETIRED 2026-08-27, on the condition this file itself set: not on an upstream
                 ticket closing, but on THIS composition — two separate Livewire components, a
                 dialog per row, lazy panels — passing again. It was re-measured against v2.35.0
                 two days earlier and was still broken then, which is why the condition was
                 written that way and why "upstream says fixed" was not enough on its own.

                 CONFIRMED against WireKit v2.37.2, the first version that carries the upstream
                 fix (it landed in v2.37.0: the overlay took its geometry only from Tailwind
                 utilities the host build had to compile, so the dialog sat in normal document
                 flow and its confirm button was below the fold). The two arms that matter —
                 "deletes an endpoint only through the alert-dialog" and the same under the
                 CSP-safe bundle — run 1961 ms TOGETHER, against the two-second bar that was the
                 retirement gate. `conflict` now refuses anything below 2.37, so the version
                 without that fix is no longer reachable for a supported install. --}}
            <x-wirekit::select
                name="windowDays"
                wire:model.live="windowDays"
                :label="__('webhooks::self-service.deliveries.window_label')"
                hideLabel
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
                hideLabel
            >
                <option value="">{{ __('webhooks::self-service.deliveries.all_statuses') }}</option>
                @foreach ($statusChoices as $choice)
                    <option value="{{ $choice->value }}">{{ __('webhooks::self-service.deliveries.status.'.$choice->value) }}</option>
                @endforeach
            </x-wirekit::select>

            {{-- Debounced, because a date input reports every keystroke while a reader types
                 the year and each one is a round trip and a query. The pair narrows WITHIN
                 the window above and can never reach past it — the window's own bound stays
                 on the query, so the older of the two bounds simply loses. --}}
            <x-wirekit::input
                type="date"
                name="from"
                wire:model.live.debounce.500ms="from"
                :label="__('webhooks::self-service.deliveries.from')"
                hideLabel
            />
            <x-wirekit::input
                type="date"
                name="until"
                wire:model.live.debounce.500ms="until"
                :label="__('webhooks::self-service.deliveries.until')"
                hideLabel
            />

        @if ($endpoints->isNotEmpty())
            <x-wirekit::select
                name="endpointId"
                wire:model.live="endpointId"
                :label="__('webhooks::self-service.deliveries.filter_label')"
                hideLabel
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
        <p role="status" class="mb-[var(--padding-wk-y-md)] text-[length:var(--text-wk-sm)] text-[color:var(--color-wk-text)]">{{ $message }}</p>
    @endif

    {{-- Keyed on the TOTAL, not on this page being empty. A page past the end — which a
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
        <x-wirekit::table
            hoverable
            :aria-label="__('webhooks::self-service.a11y.deliveries_table')"
            :table-label="__('webhooks::self-service.a11y.deliveries_table')"
        >
            <x-wirekit::table.head>
                <x-wirekit::table.row>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.deliveries.event') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.deliveries.outcome') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.deliveries.response_code') }}</x-wirekit::table.th>
                    @if ($showsErrors)
                        <x-wirekit::table.th>{{ __('webhooks::self-service.deliveries.error') }}</x-wirekit::table.th>
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
                    @php($when = $delivery->created_at->settings(['locale' => app()->getLocale()]))
                    <x-wirekit::table.row wire:key="d-{{ $delivery->id }}">
                        {{-- The row header is the event, not the outcome badge: announcing
                             "Failed" tells a screen-reader user nothing about WHICH
                             delivery failed. --}}
                        <x-wirekit::table.th headerScope="row">
                            <x-wirekit::text weight="medium">{{ $delivery->event_type }}</x-wirekit::text>
                        </x-wirekit::table.th>
                        <x-wirekit::table.td>
                            <x-wirekit::badge :intent="$intent">{{ __('webhooks::self-service.deliveries.status.'.$delivery->status->value) }}</x-wirekit::badge>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>
                            {{-- Null whenever no HTTP answer arrived at all, which is not
                                 the same as a zero. --}}
                            <x-wirekit::text size="sm" intent="muted">{{ $delivery->response_code ?? '—' }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        @if ($showsErrors)
                            {{-- Blade escapes it, so markup a receiver wrote back is text. --}}
                            <x-wirekit::table.td>
                                <x-wirekit::text size="sm" intent="muted">{{ $delivery->error ?? '—' }}</x-wirekit::text>
                            </x-wirekit::table.td>
                        @endif
                        <x-wirekit::table.td align="right">
                            <time datetime="{{ $delivery->created_at->toIso8601String() }}" title="{{ $when->isoFormat('LLL') }}">
                                <x-wirekit::text size="sm" intent="muted">{{ $when->diffForHumans() }}</x-wirekit::text>
                            </time>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            {{-- Named for the row it belongs to. Twenty buttons all called
                                 "Send again" leave a screen-reader user picking at random,
                                 and this one sends an HTTP request when picked wrong. --}}
                            <x-wirekit::button
                                size="sm"
                                surface="ghost"
                                wire:click="redeliver('{{ $delivery->id }}')"
                                wire:loading.attr="disabled"
                                {{-- Scoped like every sibling replay button. Without it the row
                                     greys out on any commit of this component, a filter change
                                     included. --}}
                                wire:target="redeliver"
                                :aria-label="__('webhooks::self-service.deliveries.replay_sr', ['label' => __('webhooks::self-service.deliveries.replay'), 'event' => $delivery->event_type, 'at' => $when->isoFormat(__('webhooks::self-service.formats.precise'))])"
                            >{{ __('webhooks::self-service.deliveries.replay') }}</x-wirekit::button>
                        </x-wirekit::table.td>
                    </x-wirekit::table.row>
                @endforeach
            </x-wirekit::table.body>
        </x-wirekit::table>

        <div class="mt-[var(--padding-wk-y-md)]">
            {{-- Its own landmark name: the endpoint list on this same page carries a
                 paginator too, and two navigation landmarks called "Pagination" leave a
                 screen-reader user picking between them at random. --}}
            {{ $deliveries->links(data: ['landmarkLabel' => __('webhooks::self-service.deliveries.pagination_label')]) }}
        </div>
    @endif
</div>
