{{-- The endpoint health status board: one row per owned endpoint with its cached
     health band + score and — once recomputed — its live success rate, p95 latency and
     sample size. The Status and Score headers sort the board, and below sm two fields above
     the cards do; per-row and all-at-once
     Recompute drive the shared health engine. Owner-scoped, WireKit-tokenized throughout. --}}
<div class="wh-portal wh-portal-health mx-auto flex max-w-5xl flex-col gap-[var(--padding-wk-y-lg)] p-[var(--padding-wk-x-lg)]" wire:key="health-matrix">
    <header>
        <x-wirekit::page-header :level="\Pushery\Webhooks\Support\UiVariant::pageHeadingLevel()" :title="__('webhooks::self-service.health_page.heading')" :description="__('webhooks::self-service.health_page.intro')">
            {{-- The conditions sit inside the slot, for the reason the portal page gives: a
                 conditional block in the default slot fills it, and the title renders empty. --}}
            <x-slot:actions>
                {{-- Absent when this board is embedded without the portal's own routes: there
                     is no endpoint page of ours to go back to. --}}
                @if ($portalUrl !== null)
                    <x-wirekit::button :href="$portalUrl" wire:navigate size="sm" surface="{{ config('webhooks.ui.secondary_surface', 'filled') }}" intent="neutral">
                        {{ __('webhooks::self-service.actions.back_to_endpoints') }}
                    </x-wirekit::button>
                @endif
                @if (! $endpoints->isEmpty())
                    <x-wirekit::button size="sm" surface="{{ config('webhooks.ui.secondary_surface', 'filled') }}" intent="neutral" wire:click="recomputeAll" wire:loading.attr="disabled" wire:target="recomputeAll">
                        {{ __('webhooks::self-service.health_page.recompute_all') }}
                    </x-wirekit::button>
                @endif
            </x-slot:actions>
        </x-wirekit::page-header>
    </header>

    @if ($message !== '')
        {{-- Why an action was refused, said where the reader is looking rather than only in a
             toast that may already have gone. --}}
        <x-wirekit::alert intent="warning" role="status">{{ $message }}</x-wirekit::alert>
    @endif

    @if ($endpointsTruncated)
        {{-- Said out loud rather than truncated in silence: a board that looks complete is how
             a reader concludes an endpoint is gone. It also states what Recompute covers, since
             the pass is bounded to exactly these rows. --}}
        <x-wirekit::text size="sm" intent="muted">{{ __('webhooks::self-service.health_page.endpoints_truncated') }}</x-wirekit::text>
    @endif

    @if ($endpoints->isEmpty())
        {{-- Level 2, not WireKit's default 3: the empty state sits directly under the board's h1. --}}
        <x-wirekit::empty-state
            icon="globe"
            variant="outline"
            :level="2"
            :title="__('webhooks::self-service.empty.no_endpoints_health.title')"
            :description="__('webhooks::self-service.empty.no_endpoints_health.description')"
        />
    @else
        {{-- Below sm the header row is out of sight with the buttons that sort the board, so the sort
             stands above the cards. From sm up the headers sort. --}}
        <div class="grid grid-cols-2 items-start gap-[var(--padding-wk-x-md)] sm:hidden" data-wh-sort-fields>
            <x-wirekit::select name="sortField" wire:model.live="sortField" :label="__('webhooks::self-service.sort.field')">
                <option value="score">{{ __('webhooks::self-service.table.score') }}</option>
                <option value="status">{{ __('webhooks::self-service.table.status') }}</option>
            </x-wirekit::select>

            <x-wirekit::select name="sortDirection" wire:model.live="sortDirection" :label="__('webhooks::self-service.sort.direction')">
                <option value="desc">{{ __('webhooks::self-service.sort.descending') }}</option>
                <option value="asc">{{ __('webhooks::self-service.sort.ascending') }}</option>
            </x-wirekit::select>
        </div>

        {{-- Below sm each endpoint stands as a card and each value as a line that names its column, as
             the endpoint list does: as a table the eight columns needed a sideways scroll on a phone,
             and the endpoint's address broke letter by letter in its narrow column. --}}
        <x-wirekit::table
            id="wh-portal-health-table"
            hoverable
            stack-below="sm"
            :aria-label="__('webhooks::self-service.a11y.health_table')"
            :table-label="__('webhooks::self-service.a11y.health_table')"
        >
            <x-wirekit::table.head>
                <x-wirekit::table.row>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.table.endpoint') }}</x-wirekit::table.th>
                    {{-- Sortable in Livewire-sort mode via WireKit's native `sort-action`: the th
                         announces aria-sort, and the header label sits in a keyboard-operable
                         <button wire:click> with a focus ring (WCAG 2.1.1). --}}
                    <x-wirekit::table.th
                        sortable
                        :sort-direction="$sortField === 'status' ? $sortDirection : null"
                        sort-action="sortBy('status')"
                    >{{ __('webhooks::self-service.table.status') }}</x-wirekit::table.th>
                    <x-wirekit::table.th
                        sortable
                        :sort-direction="$sortField === 'score' ? $sortDirection : null"
                        sort-action="sortBy('score')"
                    >{{ __('webhooks::self-service.table.score') }}</x-wirekit::table.th>
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
                        <x-wirekit::table.th headerScope="row">
                            <x-wirekit::stack gap="none">
                                @if ($endpoint->name !== null)
                                    <x-wirekit::text weight="medium">{{ $endpoint->name }}</x-wirekit::text>
                                @endif
                                <x-wirekit::text size="sm" intent="muted" class="break-words">{{ $endpoint->url }}</x-wirekit::text>
                            </x-wirekit::stack>
                        </x-wirekit::table.th>
                        <x-wirekit::table.td :label="__('webhooks::self-service.table.status')">
                            <x-wirekit::badge :intent="$healthIntent">{{ $healthLabel }}</x-wirekit::badge>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td :label="__('webhooks::self-service.table.score')">
                            <x-wirekit::text weight="medium">{{ $endpoint->health_score ?? '—' }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right" :label="__('webhooks::self-service.table.success_rate')">
                            <x-wirekit::text size="sm">{{ $report !== null ? \Pushery\Webhooks\Support\LocalizedNumber::format($report['successRate'] * 100, 1).'%' : '—' }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right" :label="__('webhooks::self-service.table.p95')">
                            <x-wirekit::text size="sm">{{ $report !== null ? \Pushery\Webhooks\Support\LocalizedNumber::format($report['p95']).' ms' : '—' }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right" :label="__('webhooks::self-service.table.sample')">
                            <x-wirekit::text size="sm">{{ $report !== null ? $report['sampleSize'] : '—' }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td :label="__('webhooks::self-service.table.as_of')">
                            <x-wirekit::text size="sm" intent="muted" class="whitespace-nowrap">{{ $endpoint->health_calculated_at?->settings(['locale' => app()->getLocale()])->diffForHumans() ?? __('webhooks::self-service.health_page.never') }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            <x-wirekit::button
                                size="sm"
                                surface="{{ config('webhooks.ui.secondary_surface', 'filled') }}" intent="neutral"
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
