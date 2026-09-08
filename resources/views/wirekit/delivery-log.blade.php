{{-- WireKit-styled stub (publish tag: webhooks-ui-wirekit). Requires pushery/wirekit
     and a Tailwind build that scans both packages' views (see the "Styling the UI" guide
     at https://docs.pushery.com/webhooks-for-laravel/guides/styling-the-ui); place behind
     your own authorization. Publish the neutral variant
     instead with the webhooks-ui tag. --}}
@php
    // Defaults, because this stub is rendered from two kinds of caller. The component passes
    // all three; the package's own suite renders the file directly through `View::make()` with a
    // hand-built array, from ten call sites across four files. Requiring the keys there would
    // make every one of them a place to remember rather than a place to read.
    //
    // The values are today's behavior exactly: an empty catalog leaves the event-type filter as
    // free text, and both actions render. The ability check is the COURTESY -- the action itself
    // still calls `authorizeAction()` and still refuses -- so defaulting it to "show" cannot open
    // anything. Defaulting it the other way would hide controls from every host that renders this
    // view directly, which is a silent regression rather than a safe one.
    $eventTypes = $eventTypes ?? [];
    $canRedeliver = $canRedeliver ?? true;
    $canPing = $canPing ?? true;
@endphp

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

        {{-- A choice where the application HAS a catalog, free text where it has none, and the
             empty catalog is the load-bearing case rather than an oversight: a host that
             declares none goes on registering any type it likes, so a select there would
             offer nothing and hide the only control that works.

             Free text is compared with an exact `where`, so a typo returns an empty list that
             is indistinguishable from "nothing was delivered" -- which is the whole reason the
             list is better wherever it exists. The catalog is the same one the self-service
             form already builds its selection from; no second source. --}}
        @if ($eventTypes !== [])
            <x-wirekit::select name="eventType" wire:model.live="eventType" :label="__('webhooks::management.filters.event_type')" hideLabel>
                <option value="">{{ __('webhooks::management.filters.all_event_types') }}</option>
                @foreach ($eventTypes as $type)
                    <option value="{{ $type }}">{{ $type }}</option>
                @endforeach
            </x-wirekit::select>
        @else
            <x-wirekit::input
                name="eventType"
                wire:model.live.debounce.300ms="eventType"
                :label="__('webhooks::management.filters.event_type')"
                hideLabel
                :placeholder="__('webhooks::management.filters.event_type_placeholder')"
            />
        @endif

        {{-- A LIST of the reader's endpoints, not a free-text id: the log is unscoped across
             every tenant, and "what happened at THIS endpoint" is the first question after an
             incident. A control that takes a number is one nobody can use without running a
             query first. --}}
        <x-wirekit::select name="endpointId" wire:model.live="endpointId" :label="__('webhooks::management.filters.endpoint')" hideLabel>
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
                    {{-- On THIS screen and not on the portal's, because this query is deliberately
                         unscoped across every tenant: rows for different endpoints stand under one
                         another, and without this cell the row does not say where it went. The
                         endpoint filter is optional, so "set a filter first" is not an answer. --}}
                    <x-wirekit::table.th>{{ __('webhooks::management.table.endpoint') }}</x-wirekit::table.th>
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
                            {{-- Name, then url, then the bare id -- the same ladder both action
                                 names already walk, so the cell and the announcement never
                                 disagree about what this endpoint is called. The id is the last
                                 rung rather than an em dash: a deleted endpoint still has rows,
                                 and "which one" is exactly what the reader is asking. --}}
                            {{ $delivery->subscription?->name ?: $delivery->subscription?->url ?: $delivery->subscription_id }}
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>
                            {{-- The stored status value keys the label; only the label is translated. --}}
                            <x-wirekit::badge :intent="$intent">{{ __('webhooks::management.status.'.$delivery->status->value) }}</x-wirekit::badge>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>{{ $delivery->attempt }}</x-wirekit::table.td>
                        {{-- The duration hangs off the code rather than standing in its own
                             column: a duration without an answer says nothing, and the pair is
                             the question a reader actually has -- it arrived, but how slow was
                             it? A receiver getting slower is the run-up to one that fails. Same
                             shape as the portal panel, so the two screens read alike. --}}
                        <x-wirekit::table.td>{{ $delivery->response_code === null ? '—' : $delivery->response_code.($delivery->duration_ms === null ? '' : ' · '.__('webhooks::management.deliveries.duration', ['ms' => \Pushery\Webhooks\Support\LocalizedNumber::format($delivery->duration_ms)])) }}</x-wirekit::table.td>
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
                        {{-- Asked per action, and the markup is the courtesy rather than the
                                 control: {@see AuthorizesOperatorActions::canAction()} walks the
                                 same two config keys in the same order the action walks before it
                                 refuses, so the two can only agree. A reader who may not replay
                                 used to get a button that answered 403 on click, which teaches
                                 nothing except that the console is unreliable.

                                 With neither config key set this renders exactly as it always
                                 has -- a host that configured nothing sees no change. --}}
                            @if ($canRedeliver)
                                {{-- The dialog, not a bare click. Pressing this sends a real
                                     HTTP request to a customer's endpoint under the delivery's
                                     ORIGINAL id, so a receiver that deduplicates treats it as one
                                     it has already seen -- and the wrong row is one keystroke
                                     away in a list of twenty identical-looking actions. The
                                     rotate and delete confirmations in the subscription manager
                                     are the pattern this follows. --}}
                                <x-wirekit::alert-dialog :name="'redeliver-'.$delivery->id">
                                    <x-slot:trigger>
                                        <x-wirekit::button size="sm" surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}" :aria-label="__('webhooks::management.a11y.redeliver_delivery', ['label' => __('webhooks::management.deliveries.redeliver'), 'event' => $delivery->event_type, 'endpoint' => $delivery->subscription?->name ?? $delivery->subscription?->url ?? $delivery->subscription_id, 'at' => $when->isoFormat('LLL')])">{{ __('webhooks::management.deliveries.redeliver') }}</x-wirekit::button>
                                    </x-slot:trigger>

                                    <x-wirekit::alert-dialog.title>{{ __('webhooks::management.redeliver_dialog.title') }}</x-wirekit::alert-dialog.title>
                                    <x-wirekit::alert-dialog.description>{{ __('webhooks::management.redeliver_dialog.description') }}</x-wirekit::alert-dialog.description>
                                    <x-wirekit::alert-dialog.actions>
                                        <x-wirekit::alert-dialog.cancel />
                                        <x-wirekit::button wire:click="redeliver('{{ $delivery->id }}')" wire:loading.attr="disabled" wire:target="redeliver">{{ __('webhooks::management.redeliver_dialog.confirm') }}</x-wirekit::button>
                                    </x-wirekit::alert-dialog.actions>
                                </x-wirekit::alert-dialog>
                            @endif

                            {{-- A ping is not confirmed: it sends nothing of the customer's and
                                 spends an allowance the component already refuses past. The
                                 ability check applies to both, because it answers "may this
                                 reader act", not "is this action dangerous". --}}
                            @if ($canPing)
                                <x-wirekit::button size="sm" surface="{{ config('webhooks.ui.secondary_surface', 'ghost') }}" wire:click="ping({{ $delivery->subscription_id }})" wire:loading.attr="disabled" wire:target="ping" :aria-label="__('webhooks::management.a11y.ping_subscription', ['label' => __('webhooks::management.deliveries.ping'), 'url' => $delivery->subscription?->url ?? $delivery->subscription_id])">{{ __('webhooks::management.deliveries.ping') }}</x-wirekit::button>
                            @endif
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
