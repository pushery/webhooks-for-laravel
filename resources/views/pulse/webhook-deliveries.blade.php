{{-- The card keeps Pulse's own structure and components; only the strings this
     package contributes are translated. The period is formatted by Pulse itself.

     Each Pulse component is called through <x-dynamic-component>. Blade resolves a
     component tag while it compiles, and `view:cache` compiles every view of this
     package, so a `pulse::` tag stopped it on an application without Pulse. A dynamic
     component is resolved when it renders, and this card renders only where Pulse
     runs. --}}
<x-dynamic-component component="pulse::card" :cols="$cols" :rows="$rows" :class="$class" wire:poll.5s="">
    <x-dynamic-component component="pulse::card-header"
        :name="__('webhooks::pulse.card.name')"
        :title="__('webhooks::pulse.card.timing', ['duration' => \Pushery\Webhooks\Support\LocalizedNumber::format($time, 2).'ms', 'at' => $runAt])"
        {{-- Keyed on the raw period token rather than on `periodForHumans()`. That helper returns
             one of four hardcoded English strings, so interpolating it left the card reading
             "letzte 6 hours" and "derniers hour" on every non-English installation. The token is
             what Pulse actually stores, and it is stable across its own display changes.

             `in_array` instead of a lookup with a fallback string: an unknown token must not
             render a translation key at people, and Pulse's own default for one is `hour`. --}}
        :details="__('webhooks::pulse.card.details.'.(in_array($period, ['6_hours', '24_hours', '7_days'], true) ? $period : 'hour'))"
    >
        <x-slot:icon>
            <x-dynamic-component component="pulse::icons.cloud-arrow-up" />
        </x-slot:icon>
    </x-dynamic-component>

    <x-dynamic-component component="pulse::scroll" :expand="$expand">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 mb-3">
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400 uppercase">{{ __('webhooks::pulse.metrics.throughput') }}</div>
                <div class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ \Pushery\Webhooks\Support\LocalizedNumber::format($throughput) }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400 uppercase">{{ __('webhooks::pulse.metrics.failure_rate') }}</div>
                {{-- text-red-400 / dark:text-red-300, and the pair is not a preference.
                     Pulse serves only its own compiled stylesheet — its layout loads
                     Pulse::css() and nothing of the host's Tailwind build — so a class that is
                     not in that file does not exist on this page. `text-red-500` and
                     `dark:text-red-400` are not in it, which is why the failure figure has
                     never actually been red. These two are. --}}
                <div class="text-xl font-bold {{ $failureRate > 0 ? 'text-red-400 dark:text-red-300' : 'text-gray-900 dark:text-gray-100' }}">
                    {{ \Pushery\Webhooks\Support\LocalizedNumber::format($failureRate, 1) }}%
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('webhooks::pulse.metrics.failed', $failures, ['count' => \Pushery\Webhooks\Support\LocalizedNumber::format($failures)]) }}</div>
                {{-- A refused delivery was never sent: its endpoint was switched off while it
                     waited in the queue. It is counted apart from the rate and shown only when
                     there is one. `?? 0` keeps a view rendered without the key working. --}}
                @if (($refused ?? 0) > 0)
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('webhooks::pulse.metrics.refused', $refused, ['count' => \Pushery\Webhooks\Support\LocalizedNumber::format($refused)]) }}</div>
                @endif
            </div>
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400 uppercase">{{ __('webhooks::pulse.metrics.avg_latency') }}</div>
                <div class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ \Pushery\Webhooks\Support\LocalizedNumber::format($avgLatency) }} ms</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400 uppercase">{{ __('webhooks::pulse.metrics.max_latency') }}</div>
                <div class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ \Pushery\Webhooks\Support\LocalizedNumber::format($maxLatency) }} ms</div>
            </div>
        </div>

        @if ($events->isEmpty())
            <x-dynamic-component component="pulse::no-results" />
        @else
            <x-dynamic-component component="pulse::table">
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-dynamic-component component="pulse::thead">
                    <tr>
                        <x-dynamic-component component="pulse::th">{{ __('webhooks::pulse.table.event') }}</x-dynamic-component>
                        <x-dynamic-component component="pulse::th" class="text-right">{{ __('webhooks::pulse.table.count') }}</x-dynamic-component>
                        <x-dynamic-component component="pulse::th" class="text-right">{{ __('webhooks::pulse.table.failures') }}</x-dynamic-component>
                        <x-dynamic-component component="pulse::th" class="text-right">{{ __('webhooks::pulse.table.avg_max') }}</x-dynamic-component>
                    </tr>
                </x-dynamic-component>
                <tbody>
                    @foreach ($events->take(100) as $event)
                        <tr wire:key="{{ $event->event }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $event->event }}-row">
                            <x-dynamic-component component="pulse::td" class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $event->event }}">
                                    {{ $event->event }}
                                </code>
                            </x-dynamic-component>
                            <x-dynamic-component component="pulse::td" numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ \Pushery\Webhooks\Support\LocalizedNumber::format($event->total) }}
                            </x-dynamic-component>
                            <x-dynamic-component component="pulse::td" numeric class="text-gray-700 dark:text-gray-300">
                                @if ($event->failed > 0)
                                    {{-- font-bold as well as the color, because at 14px on the
                                         cell's own background no red Pulse ships clears 4.5:1 —
                                         text-red-400 is 2.9:1 there. Weight is the part that
                                         carries the distinction, and it carries it for a reader
                                         who cannot separate the hues at all (WCAG 1.4.1). --}}
                                    <span class="font-bold text-red-400 dark:text-red-300">{{ \Pushery\Webhooks\Support\LocalizedNumber::format($event->failed) }}</span>
                                    {{-- The dark variant its five siblings in this file all have.
                                         Without it the parenthetical sat at 3.38:1 on the dark
                                         cell, and it is the smallest text on the card. --}}
                                    <span class="text-xs text-gray-500 dark:text-gray-400">({{ \Pushery\Webhooks\Support\LocalizedNumber::format($event->failureRate, 1) }}%)</span>
                                @else
                                    0
                                @endif
                            </x-dynamic-component>
                            <x-dynamic-component component="pulse::td" numeric class="text-gray-700 dark:text-gray-300">
                                @if ($event->avg === null)
                                    <strong>&mdash;</strong>
                                @else
                                    <strong>{{ \Pushery\Webhooks\Support\LocalizedNumber::format($event->avg) ?: '<1' }}</strong> / {{ \Pushery\Webhooks\Support\LocalizedNumber::format($event->max) }} ms
                                @endif
                            </x-dynamic-component>
                        </tr>
                    @endforeach
                </tbody>
            </x-dynamic-component>
        @endif
    </x-dynamic-component>
</x-dynamic-component>
