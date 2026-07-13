{{-- Recent queue: the newest deliveries for the tenant with a status badge and an
     inline replay. Polls so new deliveries appear without a manual refresh. --}}
@php($deliveries = $this->deliveries)
<div class="wh-dash-recent" wire:key="recent-queue" wire:poll.{{ config('webhooks.dashboard.poll_interval', '30s') }}>
    <x-wirekit::card>
        <x-wirekit::card.header>
            <x-wirekit::heading :level="3" size="sm">{{ __('webhooks::dashboard.recent.title') }}</x-wirekit::heading>
        </x-wirekit::card.header>
        <x-wirekit::card.body>
            @if ($deliveries->isEmpty())
                <x-wirekit::empty-state
                    icon="file-text"
                    :title="__('webhooks::dashboard.empty.no_deliveries.title')"
                    :description="__('webhooks::dashboard.empty.no_deliveries.description')"
                />
            @else
                <x-wirekit::table hoverable :aria-label="__('webhooks::dashboard.a11y.recent_deliveries_table')">
                    <x-wirekit::table.head>
                        <x-wirekit::table.row>
                            <x-wirekit::table.th>{{ __('webhooks::dashboard.table.status') }}</x-wirekit::table.th>
                            <x-wirekit::table.th>{{ __('webhooks::dashboard.table.event') }}</x-wirekit::table.th>
                            <x-wirekit::table.th>{{ __('webhooks::dashboard.table.code') }}</x-wirekit::table.th>
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
                            <x-wirekit::table.row wire:key="rq-{{ $delivery->id }}">
                                <x-wirekit::table.td>
                                    <x-wirekit::badge :intent="$intent">{{ __('webhooks::dashboard.status.'.$delivery->status->value) }}</x-wirekit::badge>
                                </x-wirekit::table.td>
                                <x-wirekit::table.td>{{ $delivery->event_type }}</x-wirekit::table.td>
                                <x-wirekit::table.td>{{ $delivery->response_code ?? '—' }}</x-wirekit::table.td>
                                <x-wirekit::table.td align="right">
                                    {{-- Disabled while a replay is in flight, so a double-click cannot
                                         enqueue the same delivery twice. wire:target names the method
                                         explicitly: this panel polls, and a poll must never gray the
                                         replay buttons out. --}}
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
            @endif
        </x-wirekit::card.body>
    </x-wirekit::card>
</div>
