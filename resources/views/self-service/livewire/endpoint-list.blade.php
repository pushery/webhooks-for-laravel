{{-- The tenant's own endpoint list: URL, cached health badge, event-type summary, an
     active toggle button (aria-pressed) and the per-row edit / reveal-secret / delete
     actions. Always owner-scoped, paginated, with a WireKit empty state. Deletion uses
     the WireKit alert-dialog (never wire:confirm) and is hidden when disallowed. --}}
<div class="wh-portal-list" wire:key="endpoint-list">
    <div class="mb-[var(--padding-wk-y-md)] flex flex-wrap items-center justify-between gap-[var(--padding-wk-x-md)]">
        <x-wirekit::heading :level="2" size="md">{{ __('webhooks::self-service.list.heading') }}</x-wirekit::heading>

        @if ($this->capReached)
            <x-wirekit::text size="sm" variant="muted">{{ __('webhooks::self-service.list.cap_reached') }}</x-wirekit::text>
        @else
            <x-wirekit::button wire:click="newEndpoint" wire:loading.attr="disabled" wire:target="newEndpoint">
                <x-slot:iconLeft><x-wirekit::icon name="plus" size="sm" /></x-slot:iconLeft>
                {{ __('webhooks::self-service.list.new_endpoint') }}
            </x-wirekit::button>
        @endif
    </div>

    @if ($endpoints->isEmpty())
        {{-- Outlined: this empty state stands on its own in the page body, not inside a
             card, so it needs its own chrome to read as a placeholder. --}}
        <x-wirekit::empty-state
            icon="globe"
            variant="outline"
            :title="__('webhooks::self-service.empty.no_endpoints.title')"
            :description="__('webhooks::self-service.empty.no_endpoints.description')"
        />
    @else
        <x-wirekit::table hoverable :aria-label="__('webhooks::self-service.a11y.endpoints_table')">
            <x-wirekit::table.head>
                <x-wirekit::table.row>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.table.endpoint') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.table.health') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.table.events') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::self-service.table.status') }}</x-wirekit::table.th>
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
                    <x-wirekit::table.row wire:key="ep-{{ $endpoint->id }}">
                        <x-wirekit::table.td>
                            <x-wirekit::stack gap="none">
                                @if ($endpoint->name !== null)
                                    <x-wirekit::text weight="medium">{{ $endpoint->name }}</x-wirekit::text>
                                @endif
                                <x-wirekit::text size="sm" variant="muted" class="break-all">{{ $endpoint->url }}</x-wirekit::text>
                            </x-wirekit::stack>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>
                            <x-wirekit::badge :intent="$healthIntent">
                                {{ $healthLabel }}@if ($endpoint->health_score !== null) · {{ $endpoint->health_score }}@endif
                            </x-wirekit::badge>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>
                            <x-wirekit::text size="sm">{{ implode(', ', $endpoint->event_types) ?: '—' }}</x-wirekit::text>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>
                            <x-wirekit::button
                                size="sm"
                                :surface="$endpoint->is_active ? 'filled' : 'ghost'"
                                :intent="$endpoint->is_active ? 'success' : 'neutral'"
                                wire:click="toggle({{ $endpoint->id }})"
                                :aria-pressed="$endpoint->is_active ? 'true' : 'false'"
                                :aria-label="__('webhooks::self-service.a11y.toggle_active', ['url' => $endpoint->url])"
                            >{{ $endpoint->is_active ? __('webhooks::self-service.list.active') : __('webhooks::self-service.list.disabled') }}</x-wirekit::button>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            <div class="inline-flex items-center gap-[var(--gap-wk-sm)]">
                                <x-wirekit::button
                                    size="sm"
                                    surface="ghost"
                                    wire:click="reveal({{ $endpoint->id }})"
                                    :aria-label="__('webhooks::self-service.a11y.reveal_secret', ['url' => $endpoint->url])"
                                >{{ __('webhooks::self-service.list.secret') }}</x-wirekit::button>
                                <x-wirekit::button
                                    size="sm"
                                    surface="ghost"
                                    wire:click="edit({{ $endpoint->id }})"
                                    :aria-label="__('webhooks::self-service.a11y.edit_endpoint', ['url' => $endpoint->url])"
                                >{{ __('webhooks::self-service.list.edit') }}</x-wirekit::button>
                                <x-wirekit::button
                                    size="sm"
                                    surface="ghost"
                                    :href="route('webhooks.self-service.transform', $endpoint->id)"
                                    wire:navigate
                                    :aria-label="__('webhooks::self-service.a11y.edit_transform', ['url' => $endpoint->url])"
                                >{{ __('webhooks::self-service.list.transform') }}</x-wirekit::button>

                                @if ($allowDelete)
                                    <x-wirekit::alert-dialog :name="'delete-endpoint-' . $endpoint->id">
                                        <x-slot:trigger>
                                            <x-wirekit::button
                                                size="sm"
                                                surface="ghost"
                                                intent="danger"
                                                :aria-label="__('webhooks::self-service.a11y.delete_endpoint', ['url' => $endpoint->url])"
                                            >{{ __('webhooks::self-service.list.delete') }}</x-wirekit::button>
                                        </x-slot:trigger>

                                        <x-wirekit::alert-dialog.title>{{ __('webhooks::self-service.delete_dialog.title') }}</x-wirekit::alert-dialog.title>
                                        <x-wirekit::alert-dialog.description>
                                            {{ __('webhooks::self-service.delete_dialog.description') }}
                                        </x-wirekit::alert-dialog.description>
                                        <x-wirekit::alert-dialog.actions>
                                            {{-- The default cancel label is WireKit's own English; pass the
                                                 translated one so the dialog is not half-localized. --}}
                                            <x-wirekit::alert-dialog.cancel>{{ __('webhooks::self-service.actions.cancel') }}</x-wirekit::alert-dialog.cancel>
                                            <x-wirekit::button
                                                intent="danger"
                                                wire:click="delete({{ $endpoint->id }})"
                                            >{{ __('webhooks::self-service.delete_dialog.confirm') }}</x-wirekit::button>
                                        </x-wirekit::alert-dialog.actions>
                                    </x-wirekit::alert-dialog>
                                @endif
                            </div>
                        </x-wirekit::table.td>
                    </x-wirekit::table.row>
                @endforeach
            </x-wirekit::table.body>
        </x-wirekit::table>

        <div class="mt-[var(--padding-wk-y-md)]">
            {{ $endpoints->links() }}
        </div>
    @endif
</div>
