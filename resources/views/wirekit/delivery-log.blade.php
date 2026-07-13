{{-- WireKit-styled stub (publish tag: webhooks-ui-wirekit). Requires pushery/wirekit
     and a Tailwind build that scans both packages' views (see the README's "Styling the
     UI" section); place behind your own authorization. Publish the neutral variant
     instead with the webhooks-ui tag. --}}
<x-wirekit::stack gap="md" class="wh-deliveries">
    <x-wirekit::row gap="md" class="flex-wrap items-end">
        {{-- Both filters hide their label visually, so the label reaches sighted readers
             only through assistive technology — it is translated like any other. --}}
        <x-wirekit::select wire:model.live="status" :label="__('webhooks::management.filters.status')" hideLabel>
            <option value="">{{ __('webhooks::management.filters.all_statuses') }}</option>
            <option value="pending">{{ __('webhooks::management.status_options.pending') }}</option>
            <option value="succeeded">{{ __('webhooks::management.status_options.succeeded') }}</option>
            <option value="failed">{{ __('webhooks::management.status_options.failed') }}</option>
            <option value="exhausted">{{ __('webhooks::management.status_options.exhausted') }}</option>
        </x-wirekit::select>

        <x-wirekit::input
            wire:model.live.debounce.300ms="eventType"
            :label="__('webhooks::management.filters.event_type')"
            hideLabel
            :placeholder="__('webhooks::management.filters.event_type_placeholder')"
        />
    </x-wirekit::row>

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
        <x-wirekit::table hoverable>
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
                        $intent = match ($delivery->status->value) {
                            'succeeded' => 'success',
                            'failed' => 'danger',
                            'exhausted' => 'warning',
                            default => 'neutral',
                        };
                        $when = $delivery->created_at->settings(['locale' => app()->getLocale()]);
                    @endphp
                    <x-wirekit::table.row wire:key="del-{{ $delivery->id }}">
                        <x-wirekit::table.td>{{ $delivery->event_type }}</x-wirekit::table.td>
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
                            <x-wirekit::button size="sm" surface="ghost" wire:click="redeliver('{{ $delivery->id }}')" wire:loading.attr="disabled" wire:target="redeliver">{{ __('webhooks::management.deliveries.redeliver') }}</x-wirekit::button>
                            <x-wirekit::button size="sm" surface="ghost" wire:click="ping({{ $delivery->subscription_id }})" wire:loading.attr="disabled" wire:target="ping">{{ __('webhooks::management.deliveries.ping') }}</x-wirekit::button>
                        </x-wirekit::table.td>
                    </x-wirekit::table.row>
                @endforeach
            </x-wirekit::table.body>
        </x-wirekit::table>

        {{ $deliveries->links() }}
    @endif
</x-wirekit::stack>
