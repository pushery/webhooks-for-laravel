{{-- Published stub: restyle with your design system (WireKit recommended) and
     place behind your own authorization. --}}
<div class="wh-deliveries space-y-4">
    @if ($message !== '')
        <p role="status" class="rounded border px-3 py-2">{{ $message }}</p>
    @endif

    <div class="flex flex-wrap gap-4">
        <select wire:model.live="status" class="rounded border px-3 py-2" aria-label="{{ __('webhooks::management.filters.status') }}">
            <option value="">{{ __('webhooks::management.filters.all_statuses') }}</option>
            <option value="pending">{{ __('webhooks::management.status_options.pending') }}</option>
            <option value="succeeded">{{ __('webhooks::management.status_options.succeeded') }}</option>
            <option value="failed">{{ __('webhooks::management.status_options.failed') }}</option>
            <option value="exhausted">{{ __('webhooks::management.status_options.exhausted') }}</option>
        </select>
        <input type="text" wire:model.live.debounce.300ms="eventType" placeholder="{{ __('webhooks::management.filters.event_type_placeholder') }}" aria-label="{{ __('webhooks::management.filters.event_type') }}" class="rounded border px-3 py-2">
    </div>

    <table class="w-full text-left text-sm">
        <thead>
            <tr>
                <th class="py-2">{{ __('webhooks::management.table.event') }}</th>
                <th class="py-2">{{ __('webhooks::management.table.status') }}</th>
                <th class="py-2">{{ __('webhooks::management.table.attempt') }}</th>
                <th class="py-2">{{ __('webhooks::management.table.code') }}</th>
                <th class="py-2">{{ __('webhooks::management.table.when') }}</th>
                <th class="py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($deliveries as $delivery)
                @php($when = $delivery->created_at->settings(['locale' => app()->getLocale()]))
                <tr wire:key="del-{{ $delivery->id }}" class="border-t">
                    <td class="py-2">{{ $delivery->event_type }}</td>
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
                        <button type="button" wire:click="redeliver('{{ $delivery->id }}')" class="text-indigo-600">{{ __('webhooks::management.deliveries.redeliver') }}</button>
                        <button type="button" wire:click="ping({{ $delivery->subscription_id }})" class="ml-3 text-indigo-600">{{ __('webhooks::management.deliveries.ping') }}</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $deliveries->links() }}
</div>
