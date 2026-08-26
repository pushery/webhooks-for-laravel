{{-- Published stub: restyle with your design system (WireKit recommended) and
     place behind your own authorization. --}}
<div class="wh-deliveries space-y-4">
    @if ($message !== '')
        <p role="status" class="rounded border px-3 py-2">{{ $message }}</p>
    @endif

    <div class="flex flex-wrap gap-4">
        <select name="status" wire:model.live="status" class="rounded border px-3 py-2" aria-label="{{ __('webhooks::management.filters.status') }}">
            <option value="">{{ __('webhooks::management.filters.all_statuses') }}</option>
            <option value="pending">{{ __('webhooks::management.status_options.pending') }}</option>
            <option value="succeeded">{{ __('webhooks::management.status_options.succeeded') }}</option>
            <option value="failed">{{ __('webhooks::management.status_options.failed') }}</option>
            <option value="exhausted">{{ __('webhooks::management.status_options.exhausted') }}</option>
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
    <table class="w-full text-left text-sm" aria-label="{{ __('webhooks::management.a11y.delivery_log_table') }}">
        <thead>
            <tr>
                <th class="py-2">{{ __('webhooks::management.table.event') }}</th>
                <th class="py-2">{{ __('webhooks::management.table.status') }}</th>
                <th class="py-2">{{ __('webhooks::management.table.attempt') }}</th>
                <th class="py-2">{{ __('webhooks::management.table.code') }}</th>
                <th class="py-2">{{ __('webhooks::management.table.when') }}</th>
                {{-- Not an empty <th>: an empty header announces nothing. --}}
                <th class="py-2"><span class="sr-only">{{ __('webhooks::management.table.actions') }}</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($deliveries as $delivery)
                @php($when = $delivery->created_at->settings(['locale' => app()->getLocale()]))
                <tr wire:key="del-{{ $delivery->id }}" class="border-t">
                    <th scope="row" class="py-2">{{ $delivery->event_type }}</th>
                    {{-- The stored status value keys the label; only the label is translated. --}}
                    <td class="py-2">{{ __('webhooks::management.status.'.$delivery->status->value) }}</td>
                    <td class="py-2">{{ $delivery->attempt }}</td>
                    <td class="py-2">{{ $delivery->response_code ?? '—' }}</td>
                    {{-- Relative in the cell, absolute on hover — both in the reader's locale,
                         never the raw stored timestamp. --}}
                    <td class="py-2">
                        <time datetime="{{ $delivery->created_at->toIso8601String() }}" title="{{ $when->isoFormat('LLL') }}">{{ $when->diffForHumans() }}</time>
                    </td>
                    <td class="py-2 text-right">
                        {{-- Both go over the wire and both DO something: a second redeliver
                             queues a second delivery, a second ping spends the allowance the
                             component then throttles. So neither takes a second click while
                             the first is still in flight. --}}
                        <button type="button" wire:click="redeliver('{{ $delivery->id }}')" wire:loading.attr="disabled" wire:target="redeliver" class="text-indigo-600">{{ __('webhooks::management.deliveries.redeliver') }}</button>
                        <button type="button" wire:click="ping({{ $delivery->subscription_id }})" wire:loading.attr="disabled" wire:target="ping" class="ml-3 text-indigo-600">{{ __('webhooks::management.deliveries.ping') }}</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{ $deliveries->links() }}
</div>
