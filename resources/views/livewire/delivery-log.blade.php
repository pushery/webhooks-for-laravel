{{-- Published stub: restyle with your design system (WireKit recommended) and
     place behind your own authorization. --}}
<div class="wh-deliveries space-y-4">

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
    {{-- sr-only rather than the WireKit component: this stub is the design-system-free one,
         and reaching for x-wirekit::visually-hidden here would make it require the package it
         exists to do without. --}}
    <span class="sr-only" role="status" aria-live="polite" wire:key="wh-filtered-{{ $deliveries->count() }}">{{ trans_choice('webhooks::pagination.filtered_of_unknown_total', $deliveries->count()) }}</span>
    @if ($message !== '')
        <p role="status" data-wh-region="refusal" class="rounded border px-3 py-2">{{ $message }}</p>
    @endif

    <div class="flex flex-wrap gap-4">
        <select name="status" wire:model.live="status" class="rounded border px-3 py-2" aria-label="{{ __('webhooks::management.filters.status') }}">
            <option value="">{{ __('webhooks::management.filters.all_statuses') }}</option>
            <option value="pending">{{ __('webhooks::management.status_options.pending') }}</option>
            <option value="succeeded">{{ __('webhooks::management.status_options.succeeded') }}</option>
            <option value="failed">{{ __('webhooks::management.status_options.failed') }}</option>
            <option value="exhausted">{{ __('webhooks::management.status_options.exhausted') }}</option>
            <option value="refused">{{ __('webhooks::management.status_options.refused') }}</option>
        </select>
        <input type="text" name="eventType" wire:model.live.debounce.300ms="eventType" placeholder="{{ __('webhooks::management.filters.event_type_placeholder') }}" aria-label="{{ __('webhooks::management.filters.event_type') }}" class="rounded border px-3 py-2">
        <select name="subscriptionId" wire:model.live="subscriptionId" class="rounded border px-3 py-2" aria-label="{{ __('webhooks::management.filters.endpoint') }}">
            <option value="">{{ __('webhooks::management.filters.all_endpoints') }}</option>
            @foreach ($endpoints as $endpoint)
                <option value="{{ $endpoint->id }}">{{ $endpoint->name ?: $endpoint->url }}</option>
            @endforeach
        </select>
        {{-- Debounced like the event-type field: a date input reports every keystroke while a
             reader types the year, and each one would be a round trip and a query. --}}
        <input type="date" name="from" wire:model.live.debounce.500ms="from" aria-label="{{ __('webhooks::management.filters.from') }}" class="rounded border px-3 py-2">
        <input type="date" name="until" wire:model.live.debounce.500ms="until" aria-label="{{ __('webhooks::management.filters.until') }}" class="rounded border px-3 py-2">
    </div>

    @if ($endpointsTruncated)
        {{-- Said out loud rather than truncated in silence: a list that looks complete is how a
             reader concludes an endpoint has no deliveries when it was simply never offered. --}}
        <p class="text-sm">{{ __('webhooks::management.filters.endpoints_truncated') }}</p>
    @endif

    @if ($deliveries->isEmpty())
        {{-- An empty table and a filter that matched nothing are the same picture, so the empty
             state says which one it is. Without it a reader cannot tell a quiet system from a
             filter they set by accident. --}}
        <p class="wh-empty py-8 text-center text-sm">
            <span class="block font-medium">{{ __('webhooks::management.empty.no_deliveries.title') }}</span>
            {{ __('webhooks::management.empty.no_deliveries.description') }}
        </p>
    @else
    {{-- A scroll container of its own, so the overbreadth stops here instead of reaching the
         document. Without it the whole page grows a horizontal scrollbar on a narrow viewport
         and the header and page chrome slide away with the table, because it is the DOCUMENT
         that scrolls rather than the table.

         tabindex="0" is not optional once the container exists: a scrollable region has to be
         reachable by keyboard (WCAG 2.1.1), and an unfocusable overflow div is exactly the
         failure this attribute prevents. role and name come with it so the region announces as
         something rather than as an unlabeled group -- WireKit's own table wraps itself in the
         identical four attributes, which is why its twin of this screen never had the problem. --}}
    <div class="w-full min-w-0 overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('webhooks::management.a11y.delivery_log_table') }}">
    <table class="w-full text-left text-sm" aria-label="{{ __('webhooks::management.a11y.delivery_log_table') }}">
        <thead>
            <tr>
                <th class="px-3 py-2">{{ __('webhooks::management.table.event') }}</th>
                <th class="px-3 py-2">{{ __('webhooks::management.table.status') }}</th>
                <th class="px-3 py-2">{{ __('webhooks::management.table.attempt') }}</th>
                <th class="px-3 py-2">{{ __('webhooks::management.table.code') }}</th>
                <th class="px-3 py-2">{{ __('webhooks::management.table.when') }}</th>
                {{-- Not an empty <th>: an empty header announces nothing. --}}
                <th class="px-3 py-2"><span class="sr-only">{{ __('webhooks::management.table.actions') }}</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($deliveries as $delivery)
                @php($when = $delivery->created_at->settings(['locale' => app()->getLocale()]))
                <tr wire:key="del-{{ $delivery->id }}" class="border-t">
                    <th scope="row" class="px-3 py-2">{{ $delivery->event_type }}</th>
                    {{-- The stored status value keys the label; only the label is translated. --}}
                    <td class="px-3 py-2">{{ __('webhooks::management.status.'.$delivery->status->value) }}</td>
                    <td class="px-3 py-2">{{ $delivery->attempt }}</td>
                    <td class="px-3 py-2">{{ $delivery->response_code ?? '—' }}</td>
                    {{-- Relative in the cell, absolute on hover — both in the reader's locale,
                         never the raw stored timestamp. --}}
                    <td class="px-3 py-2">
                        <time datetime="{{ $delivery->created_at->toIso8601String() }}" title="{{ $when->isoFormat('LLL') }}">{{ $when->diffForHumans() }}</time>
                    </td>
                    <td class="px-3 py-2 text-right">
                        {{-- Both go over the wire and both DO something: a second redeliver
                             queues a second delivery, a second ping spends the allowance the
                             component then throttles. So neither takes a second click while
                             the first is still in flight. --}}
                        {{-- Named per ROW, like every other action button this package ships.
                             Twenty buttons all reading "Redeliver" are twenty identical entries
                             in a screen reader's element list, where the reading order that
                             supplies the event does not exist. --}}
                        <button type="button" wire:click="redeliver('{{ $delivery->id }}')" wire:loading.attr="disabled" wire:target="redeliver" class="text-indigo-600" aria-label="{{ __('webhooks::management.a11y.redeliver_delivery', ['label' => __('webhooks::management.deliveries.redeliver'), 'event' => $delivery->event_type, 'endpoint' => $delivery->subscription?->name ?? $delivery->subscription?->url ?? $delivery->subscription_id, 'at' => $when->isoFormat('LLL')]) }}">{{ __('webhooks::management.deliveries.redeliver') }}</button>
                        <button type="button" wire:click="ping({{ $delivery->subscription_id }})" wire:loading.attr="disabled" wire:target="ping" class="ml-3 text-indigo-600" aria-label="{{ __('webhooks::management.a11y.ping_subscription', ['label' => __('webhooks::management.deliveries.ping'), 'url' => $delivery->subscription?->url ?? $delivery->subscription_id]) }}">{{ __('webhooks::management.deliveries.ping') }}</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif

    {{ $deliveries->links() }}
</div>
