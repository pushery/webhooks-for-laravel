{{-- The card keeps Pulse's own structure and components; only the strings this
     package contributes are translated. The period is formatted by Pulse itself. --}}
<x-pulse::card :cols="$cols" :rows="$rows" :class="$class" wire:poll.5s="">
    <x-pulse::card-header
        :name="__('webhooks::pulse.card.name')"
        :title="__('webhooks::pulse.card.timing', ['duration' => number_format($time, 2).'ms', 'at' => $runAt])"
        :details="__('webhooks::pulse.card.details', ['period' => $this->periodForHumans()])"
    >
        <x-slot:icon>
            <x-pulse::icons.cloud-arrow-up />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 mb-4">
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400 uppercase">{{ __('webhooks::pulse.metrics.throughput') }}</div>
                <div class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($throughput) }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400 uppercase">{{ __('webhooks::pulse.metrics.failure_rate') }}</div>
                <div class="text-xl font-bold {{ $failureRate > 0 ? 'text-red-500 dark:text-red-400' : 'text-gray-900 dark:text-gray-100' }}">
                    {{ number_format($failureRate, 1) }}%
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('webhooks::pulse.metrics.failed', ['count' => number_format($failures)]) }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400 uppercase">{{ __('webhooks::pulse.metrics.avg_latency') }}</div>
                <div class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($avgLatency) }} ms</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400 uppercase">{{ __('webhooks::pulse.metrics.max_latency') }}</div>
                <div class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($maxLatency) }} ms</div>
            </div>
        </div>

        @if ($events->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>{{ __('webhooks::pulse.table.event') }}</x-pulse::th>
                        <x-pulse::th class="text-right">{{ __('webhooks::pulse.table.count') }}</x-pulse::th>
                        <x-pulse::th class="text-right">{{ __('webhooks::pulse.table.failures') }}</x-pulse::th>
                        <x-pulse::th class="text-right">{{ __('webhooks::pulse.table.avg_max') }}</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($events->take(100) as $event)
                        <tr wire:key="{{ $event->event }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $event->event }}-row">
                            <x-pulse::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $event->event }}">
                                    {{ $event->event }}
                                </code>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ number_format($event->total) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                @if ($event->failed > 0)
                                    <span class="text-red-500 dark:text-red-400">{{ number_format($event->failed) }}</span>
                                    <span class="text-xs text-gray-500">({{ number_format($event->failureRate, 1) }}%)</span>
                                @else
                                    0
                                @endif
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                @if ($event->avg === null)
                                    <strong>&mdash;</strong>
                                @else
                                    <strong>{{ number_format($event->avg) ?: '<1' }}</strong> / {{ number_format($event->max) }} ms
                                @endif
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
