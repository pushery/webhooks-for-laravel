{{-- The full delivery table: tenant-scoped, filterable, sortable and paginated.
     Each sortable header uses WireKit's native `sort-action`: a keyboard-operable button
     (focus ring, WCAG 2.1.1) carries the wire:click and the sort-direction indicator,
     while the th itself announces aria-sort. Each row opens the detail drawer, and an
     inline replay re-queues the delivery. Empty results render the WireKit empty state;
     the control below the table is the package's own pagination view. --}}
<div class="wh-dash-deliveries" wire:key="deliveries-table">

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
    <div class="mb-[var(--padding-wk-y-md)] flex flex-wrap items-end gap-[var(--padding-wk-x-md)]">
        <x-wirekit::select name="status" wire:model.live="status" :label="__('webhooks::dashboard.filters.status')" hideLabel>
            <option value="">{{ __('webhooks::dashboard.filters.all_statuses') }}</option>
            <option value="pending">{{ __('webhooks::dashboard.status_options.pending') }}</option>
            <option value="succeeded">{{ __('webhooks::dashboard.status_options.succeeded') }}</option>
            <option value="failed">{{ __('webhooks::dashboard.status_options.failed') }}</option>
            <option value="exhausted">{{ __('webhooks::dashboard.status_options.exhausted') }}</option>
            <option value="refused">{{ __('webhooks::dashboard.status_options.refused') }}</option>
        </x-wirekit::select>

        <x-wirekit::input
            name="eventType"
            wire:model.live.debounce.300ms="eventType"
            :label="__('webhooks::dashboard.filters.event_type')"
            hideLabel
            :placeholder="__('webhooks::dashboard.filters.event_type_placeholder')"
        />
    </div>

    @if ($deliveries->isEmpty())
        {{-- Outlined: this empty state stands on its own in the page body, not inside a
             card, so it needs its own chrome to read as a placeholder. --}}
        <x-wirekit::empty-state
            icon="search"
            variant="outline"
            :title="__('webhooks::dashboard.empty.no_deliveries_found.title')"
            :description="__('webhooks::dashboard.empty.no_deliveries_found.description')"
        />
    @else
        <x-wirekit::table
            hoverable
            :aria-label="__('webhooks::dashboard.a11y.deliveries_table')"
            :table-label="__('webhooks::dashboard.a11y.deliveries_table')"
        >
            <x-wirekit::table.head>
                <x-wirekit::table.row>
                    <x-wirekit::table.th sortable :sort-direction="$sortField === 'event_type' ? $sortDirection : null" sort-action="sortBy('event_type')">{{ __('webhooks::dashboard.table.event') }}</x-wirekit::table.th>
                    <x-wirekit::table.th sortable :sort-direction="$sortField === 'status' ? $sortDirection : null" sort-action="sortBy('status')">{{ __('webhooks::dashboard.table.status') }}</x-wirekit::table.th>
                    <x-wirekit::table.th sortable :sort-direction="$sortField === 'attempt' ? $sortDirection : null" sort-action="sortBy('attempt')">{{ __('webhooks::dashboard.table.attempt') }}</x-wirekit::table.th>
                    <x-wirekit::table.th sortable :sort-direction="$sortField === 'response_code' ? $sortDirection : null" sort-action="sortBy('response_code')">{{ __('webhooks::dashboard.table.code') }}</x-wirekit::table.th>
                    <x-wirekit::table.th sortable :sort-direction="$sortField === 'duration_ms' ? $sortDirection : null" sort-action="sortBy('duration_ms')">{{ __('webhooks::dashboard.table.duration') }}</x-wirekit::table.th>
                    <x-wirekit::table.th sortable :sort-direction="$sortField === 'created_at' ? $sortDirection : null" sort-action="sortBy('created_at')">{{ __('webhooks::dashboard.table.when') }}</x-wirekit::table.th>
                    <x-wirekit::table.th align="right">{{ __('webhooks::dashboard.table.actions') }}</x-wirekit::table.th>
                </x-wirekit::table.row>
            </x-wirekit::table.head>
            <x-wirekit::table.body>
                @foreach ($deliveries as $delivery)
                    @php($intent = $delivery->status->intent())
                    @php($when = \Pushery\Webhooks\Dashboard\DashboardTimezone::apply($delivery->created_at)->settings(['locale' => app()->getLocale()]))
                    <x-wirekit::table.row wire:key="dt-{{ $delivery->id }}">
                        <x-wirekit::table.th headerScope="row">
                            {{-- A native button rather than the library's, and this is the one
                                 place in this view that leaves it. A table-cell trigger has to
                                 read as the cell's own text — the event type — not as a control
                                 sitting in a cell, so it takes the accent color and nothing
                                 else. The library's button brings its own padding, height and
                                 surface, and every row would then be a row of buttons.
                                 Said out loud because a silent escape in a file that uses the
                                 library twenty lines further down reads as an oversight. --}}
                            <button type="button" wire:click="viewDelivery('{{ $delivery->id }}')" class="cursor-pointer text-[color:var(--color-wk-accent)]" aria-label="{{ __('webhooks::dashboard.a11y.view_delivery', ['event' => $delivery->event_type, 'endpoint' => $delivery->subscription?->name ?? $delivery->subscription?->url ?? $delivery->subscription_id, 'at' => $when->isoFormat(__('webhooks::dashboard.formats.precise'))]) }}">
                                {{ $delivery->event_type }}
                            </button>
                        </x-wirekit::table.th>
                        <x-wirekit::table.td>
                            <x-wirekit::badge :intent="$intent">{{ __('webhooks::dashboard.status.'.$delivery->status->value) }}</x-wirekit::badge>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>{{ $delivery->attempt }}</x-wirekit::table.td>
                        <x-wirekit::table.td>{{ $delivery->response_code ?? '—' }}</x-wirekit::table.td>
                        <x-wirekit::table.td>{{ $delivery->duration_ms !== null ? $delivery->duration_ms . ' ms' : '—' }}</x-wirekit::table.td>
                        <x-wirekit::table.td>
                            {{-- Relative in the cell an operator scans, absolute on hover — both
                                 in the reader's locale and in the dashboard's display zone,
                                 never the raw stored timestamp. The datetime attribute stays
                                 ISO-8601 with its own offset, which is what a machine reads and
                                 what a zone change must not reshape.

                                 Through `formats.absolute` rather than a literal `LLL`. The two
                                 accessible names on this very row already read that key, so the
                                 tooltip was the one value on the row rendered by a different rule —
                                 without the `z` its own lang file argues for at length, and out of
                                 reach of the host override that key documents. On a dashboard with
                                 `dashboard.timezone` set, a sighted operator hovering this column
                                 saw a time with nothing saying which clock it was, while a
                                 screen-reader user on the same row was told. --}}
                            <time datetime="{{ $delivery->created_at->toIso8601String() }}" title="{{ $when->isoFormat(__('webhooks::dashboard.formats.absolute')) }}">{{ $when->diffForHumans() }}</time>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            {{-- Disabled while a replay is in flight, so a double-click cannot
                                 enqueue the same delivery twice. wire:target names the method:
                                 this panel also sorts and opens the drawer, and only a replay
                                 may gray the replay buttons out. --}}
                            <x-wirekit::button
                                size="sm"
                                surface="ghost"
                                wire:click="redeliver('{{ $delivery->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="redeliver"
                                :aria-label="__('webhooks::dashboard.a11y.replay_delivery', ['label' => __('webhooks::dashboard.table.replay'), 'event' => $delivery->event_type, 'endpoint' => $delivery->subscription?->name ?? $delivery->subscription?->url ?? $delivery->subscription_id, 'at' => $when->isoFormat(__('webhooks::dashboard.formats.precise'))])"
                            >{{ __('webhooks::dashboard.table.replay') }}</x-wirekit::button>
                        </x-wirekit::table.td>
                    </x-wirekit::table.row>
                @endforeach
            </x-wirekit::table.body>
        </x-wirekit::table>

        <div class="mt-[var(--padding-wk-y-md)]">
            {{ $deliveries->links() }}
        </div>
    @endif
</div>
