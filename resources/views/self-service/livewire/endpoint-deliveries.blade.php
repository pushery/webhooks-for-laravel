{{-- The tenant's own delivery log: event, outcome badge, response code and when, newest
     first, optionally narrowed to one endpoint. Owner-scoped on the delivery row's own
     morph columns. Deliberately shows no body of any kind — not the outbound payload and
     not the stored error, which can quote back whatever the receiver wrote. --}}
<div class="wh-portal-deliveries" wire:key="endpoint-deliveries">
    <div class="mb-[var(--padding-wk-y-md)] flex flex-wrap items-center justify-between gap-[var(--padding-wk-x-md)]">
        <x-wirekit::heading :level="2" size="md">{{ __('webhooks::self-service.deliveries.heading') }}</x-wirekit::heading>

        @if ($endpoints->isNotEmpty())
            {{-- A native select, deliberately, and the one place these screens step outside
                 the component library. Bound with wire:model.live inside the library's own
                 select, this control makes the endpoint list's delete confirmation
                 unclickable: the dialog opens, and the click on its destructive action never
                 lands. Measured three ways on the same page — library select 15s timeout,
                 native select 0.9s pass, no select at all 1.0s pass — so it is the control,
                 not the live binding. Reported upstream; this reverts to the library's
                 component the moment that lands. --}}
            <label for="wh-deliveries-filter" class="sr-only">{{ __('webhooks::self-service.deliveries.filter_label') }}</label>
            <select
                id="wh-deliveries-filter"
                wire:model.live="endpointId"
                class="wk-field h-[var(--size-wk-md)] rounded-[var(--radius-wk-md)] border border-[color:var(--color-wk-border)] bg-[var(--color-wk-bg)] px-[var(--padding-wk-x-md)] text-[length:var(--text-wk-md)] text-[color:var(--color-wk-text)]"
            >
                <option value="">{{ __('webhooks::self-service.deliveries.all_endpoints') }}</option>
                @foreach ($endpoints as $endpoint)
                    <option value="{{ $endpoint->id }}">{{ $endpoint->name ?? $endpoint->url }}</option>
                @endforeach
            </select>
        @endif
    </div>

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
            :description="$endpointId === null
                ? __('webhooks::self-service.empty.no_deliveries.description')
                : __('webhooks::self-service.empty.no_deliveries.filtered')"
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
                    <x-wirekit::table.th align="right">{{ __('webhooks::self-service.deliveries.when') }}</x-wirekit::table.th>
                </x-wirekit::table.row>
            </x-wirekit::table.head>
            <x-wirekit::table.body>
                @foreach ($deliveries as $delivery)
                    {{-- The same mapping the operator dashboard uses: exhausted is danger,
                         not warning. Two surfaces disagreeing about which outcome is grave
                         is a difference a reader would have to learn. --}}
                    @php($intent = match ($delivery->status->value) {
                        'succeeded' => 'success',
                        'failed', 'exhausted' => 'danger',
                        default => 'warning',
                    })
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
                            <x-wirekit::text size="sm" variant="muted">{{ $delivery->response_code ?? '—' }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            <time datetime="{{ $delivery->created_at->toIso8601String() }}" title="{{ $when->isoFormat('LLL') }}">
                                <x-wirekit::text size="sm" variant="muted">{{ $when->diffForHumans() }}</x-wirekit::text>
                            </time>
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
