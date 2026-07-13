{{-- WireKit-styled stub (publish tag: webhooks-ui-wirekit). Requires pushery/wirekit
     and a Tailwind build that scans both packages' views (see the README's "Styling the
     UI" section); place behind your own authorization. Publish the neutral variant
     instead with the webhooks-ui tag. --}}
<x-wirekit::stack gap="lg" class="wh-subscriptions">
    <x-wirekit::card>
        <x-wirekit::card.body>
            <form wire:submit="create">
                <x-wirekit::stack gap="md">
                    <x-wirekit::input
                        :label="__('webhooks::management.form.name_label')"
                        wire:model="name"
                        :error="$errors->first('name') ?: null"
                    />

                    <x-wirekit::input
                        type="url"
                        :label="__('webhooks::management.form.url_label')"
                        :placeholder="__('webhooks::management.form.url_placeholder')"
                        wire:model="url"
                        :error="$errors->first('url') ?: null"
                    />

                    <x-wirekit::field :label="__('webhooks::management.form.event_types_legend')" :error="$errors->first('eventTypes') ?: null">
                        <x-wirekit::stack gap="xs">
                            @forelse ($availableEventTypes as $type)
                                <x-wirekit::checkbox wire:model="eventTypes" value="{{ $type }}" label="{{ $type }}" />
                            @empty
                                {{-- The path travels through the sentence as a placeholder, so a locale
                                     can put it wherever its grammar wants it. --}}
                                <x-wirekit::text size="sm" variant="muted">
                                    {{ __('webhooks::management.form.event_types_empty', ['file' => 'config/webhooks.php']) }}
                                </x-wirekit::text>
                            @endforelse
                        </x-wirekit::stack>
                    </x-wirekit::field>

                    <div>
                        <x-wirekit::button type="submit">{{ __('webhooks::management.form.submit') }}</x-wirekit::button>
                    </div>
                </x-wirekit::stack>
            </form>
        </x-wirekit::card.body>
    </x-wirekit::card>

    @if ($newSecret)
        <x-wirekit::alert variant="success" :title="__('webhooks::management.secret.heading')">
            <x-wirekit::code class="wh-new-secret break-all">{{ $newSecret }}</x-wirekit::code>
        </x-wirekit::alert>
    @endif

    @if ($subscriptions->isEmpty())
        {{-- The zero-row case is the first thing every new install sees, so the stub ships
             the empty state rather than a bare header row over nothing. --}}
        <x-wirekit::empty-state
            icon="globe"
            variant="outline"
            :title="__('webhooks::management.empty.no_subscriptions.title')"
            :description="__('webhooks::management.empty.no_subscriptions.description')"
        />
    @else
        <x-wirekit::table hoverable>
            <x-wirekit::table.head>
                <x-wirekit::table.row>
                    <x-wirekit::table.th>{{ __('webhooks::management.table.endpoint') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::management.table.events') }}</x-wirekit::table.th>
                    <x-wirekit::table.th>{{ __('webhooks::management.table.status') }}</x-wirekit::table.th>
                    {{-- The actions column carries no visible header, but it still needs an
                         accessible name: an empty th announces nothing to a screen reader. --}}
                    <x-wirekit::table.th align="right">
                        <x-wirekit::visually-hidden>{{ __('webhooks::management.table.actions') }}</x-wirekit::visually-hidden>
                    </x-wirekit::table.th>
                </x-wirekit::table.row>
            </x-wirekit::table.head>
            <x-wirekit::table.body>
                @foreach ($subscriptions as $subscription)
                    <x-wirekit::table.row wire:key="sub-{{ $subscription->id }}">
                        <x-wirekit::table.td>
                            <x-wirekit::stack gap="none">
                                <x-wirekit::text weight="medium">{{ $subscription->name ?? '—' }}</x-wirekit::text>
                                <x-wirekit::text size="sm" variant="muted">{{ $subscription->url }}</x-wirekit::text>
                            </x-wirekit::stack>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td>{{ implode(', ', $subscription->event_types) }}</x-wirekit::table.td>
                        <x-wirekit::table.td>
                            <x-wirekit::badge :intent="$subscription->is_active ? 'success' : 'neutral'">
                                {{ $subscription->is_active ? __('webhooks::management.subscription.active') : __('webhooks::management.subscription.disabled') }}
                            </x-wirekit::badge>
                        </x-wirekit::table.td>
                        <x-wirekit::table.td align="right">
                            <x-wirekit::button size="sm" surface="ghost" wire:click="toggle({{ $subscription->id }})" wire:loading.attr="disabled" wire:target="toggle">
                                {{ $subscription->is_active ? __('webhooks::management.subscription.disable') : __('webhooks::management.subscription.enable') }}
                            </x-wirekit::button>

                            {{-- Deleting an endpoint is irreversible and stops a live production
                                 integration, so it is confirmed through the WireKit alert-dialog —
                                 never a bare one-click destroy, and never wire:confirm. This is the
                                 pattern to copy when you restyle the stub. --}}
                            <x-wirekit::alert-dialog :name="'delete-subscription-' . $subscription->id">
                                <x-slot:trigger>
                                    <x-wirekit::button
                                        size="sm"
                                        surface="ghost"
                                        intent="danger"
                                        :aria-label="__('webhooks::management.a11y.delete_subscription', ['url' => $subscription->url])"
                                    >{{ __('webhooks::management.subscription.delete') }}</x-wirekit::button>
                                </x-slot:trigger>

                                <x-wirekit::alert-dialog.title>{{ __('webhooks::management.delete_dialog.title') }}</x-wirekit::alert-dialog.title>
                                <x-wirekit::alert-dialog.description>
                                    {{ __('webhooks::management.delete_dialog.description') }}
                                </x-wirekit::alert-dialog.description>
                                <x-wirekit::alert-dialog.actions>
                                    {{-- The default cancel label is WireKit's own English; pass the
                                         translated one so the dialog is not half-localized. --}}
                                    <x-wirekit::alert-dialog.cancel>{{ __('webhooks::management.actions.cancel') }}</x-wirekit::alert-dialog.cancel>
                                    <x-wirekit::button
                                        intent="danger"
                                        wire:click="delete({{ $subscription->id }})"
                                    >{{ __('webhooks::management.delete_dialog.confirm') }}</x-wirekit::button>
                                </x-wirekit::alert-dialog.actions>
                            </x-wirekit::alert-dialog>
                        </x-wirekit::table.td>
                    </x-wirekit::table.row>
                @endforeach
            </x-wirekit::table.body>
        </x-wirekit::table>
    @endif
</x-wirekit::stack>
