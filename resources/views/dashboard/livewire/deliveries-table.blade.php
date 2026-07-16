{{-- The full delivery table: tenant-scoped, filterable, sortable and paginated.
     Each sortable header uses WireKit's native `sort-action`: a keyboard-operable button
     (focus ring, WCAG 2.1.1) carries the wire:click and the sort-direction indicator,
     while the th itself announces aria-sort. Each row opens the detail drawer, and an
     inline replay re-queues the delivery. Empty results render the WireKit empty state;
     the control below the table is the package's own pagination view. --}}
<div class="wh-dash-deliveries" wire:key="deliveries-table">
    <div class="mb-[var(--padding-wk-y-md)] flex flex-wrap items-end gap-[var(--padding-wk-x-md)]">
        <x-wirekit::select wire:model.live="status" :label="__('webhooks::dashboard.filters.status')" hideLabel>
            <option value="">{{ __('webhooks::dashboard.filters.all_statuses') }}</option>
            <option value="pending">{{ __('webhooks::dashboard.status_options.pending') }}</option>
            <option value="succeeded">{{ __('webhooks::dashboard.status_options.succeeded') }}</option>
            <option value="failed">{{ __('webhooks::dashboard.status_options.failed') }}</option>
            <option value="exhausted">{{ __('webhooks::dashboard.status_options.exhausted') }}</option>
        </x-wirekit::select>

        <x-wirekit::input
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
        <x-wirekit::table hoverable :aria-label="__('webhooks::dashboard.a11y.deliveries_table')">
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
                    @php($intent = match ($delivery->status->value) {
                        'succeeded' => 'success',
                        'failed', 'exhausted' => 'danger',
                        default => 'warning',
                    })
                    @php($when = $delivery->created_at->settings(['locale' => app()->getLocale()]))
                    <x-wirekit::table.row wire:key="dt-{{ $delivery->id }}">
                        <x-wirekit::table.td>
                            <button type="button" wire:click="viewDelivery('{{ $delivery->id }}')" class="cursor-pointer text-[color:var(--color-wk-accent)]" aria-label="{{ __('webhooks::dashboard.a11y.view_delivery', ['event' => $delivery->event_type]) }}">
                                {{ $delivery->event_type }}
                            </button>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>
                            <x-wirekit::badge :intent="$intent">{{ __('webhooks::dashboard.status.'.$delivery->status->value) }}</x-wirekit::badge>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>{{ $delivery->attempt }}</x-wirekit::table.td>
                        <x-wirekit::table.td>{{ $delivery->response_code ?? '—' }}</x-wirekit::table.td>
                        <x-wirekit::table.td>{{ $delivery->duration_ms !== null ? $delivery->duration_ms . ' ms' : '—' }}</x-wirekit::table.td>
                        <x-wirekit::table.td>
                            {{-- Relative in the cell an operator scans, absolute on hover — both
                                 in the reader's locale, never the raw stored timestamp. --}}
                            <time datetime="{{ $delivery->created_at->toIso8601String() }}" title="{{ $when->isoFormat('LLL') }}">{{ $when->diffForHumans() }}</time>
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
                                :aria-label="__('webhooks::dashboard.a11y.replay_delivery', ['event' => $delivery->event_type])"
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
