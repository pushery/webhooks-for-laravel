{{-- WireKit-styled stub (publish tag: webhooks-ui-wirekit). Requires pushery/wirekit
     and a Tailwind build that scans both packages' views (see the "Styling the UI" guide
     at https://docs.pushery.com/webhooks-for-laravel/guides/styling-the-ui); place behind
     your own authorization. Publish the neutral variant
     instead with the webhooks-ui tag. --}}
<x-wirekit::stack gap="md" class="wh-deliveries">

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
    <x-wirekit::visually-hidden role="status" aria-live="polite" wire:key="wh-filtered-{{ $deliveries->count() }}">{{ trans_choice('webhooks::pagination.filtered_of_unknown_total', $deliveries->count()) }}</x-wirekit::visually-hidden>
    {{-- The component's two refusals — a redeliver against a switched-off endpoint, and a ping
         past its allowance — reach the reader only through here. Rendered CONDITIONALLY rather
         than as an always-present region: a permanently blank live region gets announced on
         every update by some screen readers, which teaches the reader to ignore it. --}}
    @if ($message !== '')
        <x-wirekit::alert intent="warning" role="status" data-wh-region="refusal">{{ $message }}</x-wirekit::alert>
    @endif

    <x-wirekit::row gap="md" class="flex-wrap items-end">
        {{-- Both filters hide their label visually, so the label reaches sighted readers
             only through assistive technology — it is translated like any other. --}}
        <x-wirekit::select name="status" wire:model.live="status" :label="__('webhooks::management.filters.status')" hideLabel>
            <option value="">{{ __('webhooks::management.filters.all_statuses') }}</option>
            <option value="pending">{{ __('webhooks::management.status_options.pending') }}</option>
            <option value="succeeded">{{ __('webhooks::management.status_options.succeeded') }}</option>
            <option value="failed">{{ __('webhooks::management.status_options.failed') }}</option>
            <option value="exhausted">{{ __('webhooks::management.status_options.exhausted') }}</option>
            <option value="refused">{{ __('webhooks::management.status_options.refused') }}</option>
        </x-wirekit::select>

        <x-wirekit::input
            name="eventType"
            wire:model.live.debounce.300ms="eventType"
            :label="__('webhooks::management.filters.event_type')"
            hideLabel
            :placeholder="__('webhooks::management.filters.event_type_placeholder')"
        />

        {{-- A LIST of the reader's endpoints, not a free-text id: the log is unscoped across
             every tenant, and "what happened at THIS endpoint" is the first question after an
             incident. A control that takes a number is one nobody can use without running a
             query first. --}}
        <x-wirekit::select name="subscriptionId" wire:model.live="subscriptionId" :label="__('webhooks::management.filters.endpoint')" hideLabel>
            <option value="">{{ __('webhooks::management.filters.all_endpoints') }}</option>
            @foreach ($endpoints as $endpoint)
                <option value="{{ $endpoint->id }}">{{ $endpoint->name ?: $endpoint->url }}</option>
            @endforeach
        </x-wirekit::select>

        {{-- Debounced like the event-type field: a date input reports every keystroke while a
             reader types the year, and each one would be a round trip and a query. --}}
        <x-wirekit::input
            type="date"
            name="from"
            wire:model.live.debounce.500ms="from"
            :label="__('webhooks::management.filters.from')"
            hideLabel
        />

        <x-wirekit::input
            type="date"
            name="until"
            wire:model.live.debounce.500ms="until"
            :label="__('webhooks::management.filters.until')"
            hideLabel
        />
    </x-wirekit::row>

    @if ($endpointsTruncated)
        {{-- Said out loud rather than truncated in silence: a list that looks complete is how a
             reader concludes an endpoint has no deliveries when it was simply never offered. --}}
        <x-wirekit::text size="sm" intent="muted">{{ __('webhooks::management.filters.endpoints_truncated') }}</x-wirekit::text>
    @endif

    @if ($deliveries->isEmpty())
        {{-- The zero-row case is the first thing every new install sees, so the stub ships
             the empty state rather than a bare header row over nothing. --}}
        <x-wirekit::empty-state
            icon="search"
            variant="outline"
            :title="__('webhooks::management.empty.no_deliveries.title')"
            :description="__('webhooks::management.empty.no_deliveries.description')"
        />
    @else
        <x-wirekit::table
            hoverable
            :aria-label="__('webhooks::management.a11y.delivery_log_table')"
            :table-label="__('webhooks::management.a11y.delivery_log_table')"
        >
            <x-wirekit::table.head>
                <x-wirekit::table.row>
                    <x-wirekit::table.th>{{ __('webhooks::management.table.event') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::management.table.status') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::management.table.attempt') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::management.table.code') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::management.table.when') }}</x-wirekit::table.th>
                    {{-- The actions column carries no visible header, but it still needs an
                         accessible name: an empty th announces nothing to a screen reader. --}}
                    <x-wirekit::table.th align="right">
                        <x-wirekit::visually-hidden>{{ __('webhooks::management.table.actions') }}</x-wirekit::visually-hidden>
                    </x-wirekit::table.th>
                </x-wirekit::table.row>
            </x-wirekit::table.head>
            <x-wirekit::table.body>
                @foreach ($deliveries as $delivery)
                    @php
                        // From the enum, not from a ladder here. This stub had its own, and it
                        // disagreed with the other three views: exhausted was `warning` -- the
                        // same amber it uses for pending -- so the worst outcome a delivery has
                        // read one step too harmless on precisely the view a host copies.
                        $intent = $delivery->status->intent();
                        $when = $delivery->created_at->settings(['locale' => app()->getLocale()]);
                    @endphp
                    <x-wirekit::table.row wire:key="del-{{ $delivery->id }}">
                        <x-wirekit::table.th headerScope="row">{{ $delivery->event_type }}</x-wirekit::table.th>
                        <x-wirekit::table.td>
                            {{-- The stored status value keys the label; only the label is translated. --}}
                            <x-wirekit::badge :intent="$intent">{{ __('webhooks::management.status.'.$delivery->status->value) }}</x-wirekit::badge>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>{{ $delivery->attempt }}</x-wirekit::table.td>
                        <x-wirekit::table.td>{{ $delivery->response_code ?? '—' }}</x-wirekit::table.td>
                        <x-wirekit::table.td>
                            {{-- Relative in the cell, absolute on hover — both in the reader's
                                 locale, never the raw stored timestamp. --}}
                            <time datetime="{{ $delivery->created_at->toIso8601String() }}" title="{{ $when->isoFormat('LLL') }}">{{ $when->diffForHumans() }}</time>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            {{-- Named per ROW. Twenty buttons all reading "Redeliver" are twenty
                                 identical entries in a screen reader's element list, and reading
                                 order -- where the row header supplies the event -- is exactly
                                 what an element list does not have. Picking the wrong one sends
                                 a real HTTP request to a customer's endpoint.

                                 The visible word is interpolated rather than described, so the
                                 name CONTAINS it in all seven languages (WCAG 2.5.3). --}}
                            {{-- `LLL` without a zone, unlike the dashboard's names: this console renders no
                             zone anywhere — its own <time title> is a bare LLL — and a name that carried
                             one would be the only place on the screen that did. The dashboard has a
                             translatable `formats.absolute` for exactly that reason; this namespace has
                             none, and inventing one here would be a wider change than a label needs. --}}
                        <x-wirekit::button size="sm" surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}" wire:click="redeliver('{{ $delivery->id }}')" wire:loading.attr="disabled" wire:target="redeliver" :aria-label="__('webhooks::management.a11y.redeliver_delivery', ['label' => __('webhooks::management.deliveries.redeliver'), 'event' => $delivery->event_type, 'endpoint' => $delivery->subscription?->name ?? $delivery->subscription?->url ?? $delivery->subscription_id, 'at' => $when->isoFormat('LLL')])">{{ __('webhooks::management.deliveries.redeliver') }}</x-wirekit::button>
                            <x-wirekit::button size="sm" surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}" wire:click="ping({{ $delivery->subscription_id }})" wire:loading.attr="disabled" wire:target="ping" :aria-label="__('webhooks::management.a11y.ping_subscription', ['label' => __('webhooks::management.deliveries.ping'), 'url' => $delivery->subscription?->url ?? $delivery->subscription_id])">{{ __('webhooks::management.deliveries.ping') }}</x-wirekit::button>
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
    {{ $deliveries->links() }}
</x-wirekit::stack>
