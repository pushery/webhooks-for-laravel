{{-- The endpoint health status board: one row per owned endpoint with its cached
     health band + score and — once recomputed — its live success rate, p95 latency and
     sample size. The Status and Score headers sort the board; per-row and all-at-once
     Recompute drive the shared health engine. Owner-scoped, WireKit-tokenized throughout. --}}
@php($headerButton = '-mx-[var(--padding-wk-x-md)] -my-[var(--padding-wk-y-md)] w-[calc(100%+2*var(--padding-wk-x-md))] cursor-pointer px-[var(--padding-wk-x-md)] py-[var(--padding-wk-y-md)] text-left')
<div class="wh-portal wh-portal-health mx-auto flex max-w-5xl flex-col gap-[var(--padding-wk-y-lg)] p-[var(--padding-wk-x-lg)]" wire:key="health-matrix">
    <header class="flex flex-wrap items-start justify-between gap-[var(--padding-wk-x-md)]">
        <x-wirekit::stack gap="sm">
            <x-wirekit::heading :level="1" size="lg">{{ __('webhooks::self-service.health_page.heading') }}</x-wirekit::heading>
            <x-wirekit::text variant="muted">{{ __('webhooks::self-service.health_page.intro') }}</x-wirekit::text>
        </x-wirekit::stack>
        <div class="flex items-center gap-[var(--gap-wk-sm)]">
            <x-wirekit::button :href="route('webhooks.self-service')" wire:navigate size="sm" surface="ghost" intent="neutral">
                {{ __('webhooks::self-service.actions.back_to_endpoints') }}
            </x-wirekit::button>
            @if (! $endpoints->isEmpty())
                <x-wirekit::button size="sm" surface="ghost" wire:click="recomputeAll" wire:loading.attr="disabled" wire:target="recomputeAll">
                    {{ __('webhooks::self-service.health_page.recompute_all') }}
                </x-wirekit::button>
            @endif
        </div>
    </header>

    @if ($endpoints->isEmpty())
        <x-wirekit::empty-state
            icon="globe"
            variant="outline"
            :title="__('webhooks::self-service.empty.no_endpoints_health.title')"
            :description="__('webhooks::self-service.empty.no_endpoints_health.description')"
        />
    @else
        <x-wirekit::table hoverable :aria-label="__('webhooks::self-service.a11y.health_table')">
            <x-wirekit::table.head>
                <x-wirekit::table.row>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.table.endpoint') }}</x-wirekit::table.th>
                    {{-- Sortable in Livewire-sort mode: the th carries aria-sort, and a real
                         nested button carries the wire:click so the sort is keyboard-operable
                         (a bare sortable th renders a non-focusable header). --}}
                    <x-wirekit::table.th
                        sortable
                        :sort-direction="$sortField === 'status' ? $sortDirection : null"
                    >
                        <button type="button" wire:click="sortBy('status')" class="{{ $headerButton }}">{{ __('webhooks::self-service.table.status') }}</button>
                    </x-wirekit::table.th>
                    <x-wirekit::table.th
                        sortable
                        :sort-direction="$sortField === 'score' ? $sortDirection : null"
                    >
                        <button type="button" wire:click="sortBy('score')" class="{{ $headerButton }}">{{ __('webhooks::self-service.table.score') }}</button>
                    </x-wirekit::table.th>
                    <x-wirekit::table.th align="right">{{ __('webhooks::self-service.table.success_rate') }}</x-wirekit::table.th>
                    <x-wirekit::table.th align="right">{{ __('webhooks::self-service.table.p95') }}</x-wirekit::table.th>
                    <x-wirekit::table.th align="right">{{ __('webhooks::self-service.table.sample') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.table.as_of') }}</x-wirekit::table.th>
                    <x-wirekit::table.th align="right">{{ __('webhooks::self-service.table.actions') }}</x-wirekit::table.th>
                </x-wirekit::table.row>
            </x-wirekit::table.head>
            <x-wirekit::table.body>
                @foreach ($endpoints as $endpoint)
                    @php($healthIntent = match ($endpoint->health_status) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'failing' => 'danger',
                        default => 'neutral',
                    })
                    {{-- The badge label is translated for display; the key is the stored
                         health_status value, which never changes. --}}
                    @php($healthLabel = __('webhooks::self-service.health.'.($endpoint->health_status ?? 'unknown')))
                    @php($report = $reports[$endpoint->id] ?? null)
                    <x-wirekit::table.row wire:key="health-{{ $endpoint->id }}">
                        <x-wirekit::table.td>
                            <x-wirekit::stack gap="none">
                                @if ($endpoint->name !== null)
                                    <x-wirekit::text weight="medium">{{ $endpoint->name }}</x-wirekit::text>
                                @endif
                                <x-wirekit::text size="sm" variant="muted" class="break-all">{{ $endpoint->url }}</x-wirekit::text>
                            </x-wirekit::stack>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>
                            <x-wirekit::badge :intent="$healthIntent">{{ $healthLabel }}</x-wirekit::badge>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>
                            <x-wirekit::text weight="medium">{{ $endpoint->health_score ?? '—' }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            <x-wirekit::text size="sm">{{ $report !== null ? number_format($report['successRate'] * 100, 1).'%' : '—' }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            <x-wirekit::text size="sm">{{ $report !== null ? number_format($report['p95']).' ms' : '—' }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            <x-wirekit::text size="sm">{{ $report !== null ? $report['sampleSize'] : '—' }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>
                            <x-wirekit::text size="sm" variant="muted">{{ $endpoint->health_calculated_at?->settings(['locale' => app()->getLocale()])->diffForHumans() ?? __('webhooks::self-service.health_page.never') }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            <x-wirekit::button
                                size="sm"
                                surface="ghost"
                                wire:click="recompute({{ $endpoint->id }})"
                                wire:loading.attr="disabled"
                                wire:target="recompute"
                                :aria-label="__('webhooks::self-service.a11y.recompute_health', ['url' => $endpoint->url])"
                            >{{ __('webhooks::self-service.health_page.recompute') }}</x-wirekit::button>
                        </x-wirekit::table.td>
                    </x-wirekit::table.row>
                @endforeach
            </x-wirekit::table.body>
        </x-wirekit::table>
    @endif

    <x-wirekit::toast-region />
</div>
