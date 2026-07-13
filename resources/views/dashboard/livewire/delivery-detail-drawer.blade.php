{{-- Delivery detail drawer. The open/close state is owned by Livewire (the panel is
     only in the DOM when a delivery is selected) rather than WireKit drawer's
     Alpine-only toggle, so the cross-component open from the table stays
     deterministic and server-testable. A small Alpine layer supplies the modal
     keyboard model the bespoke dialog would otherwise lack: focus moves into the panel
     on open, Tab is trapped inside it, Escape closes it, and focus returns to the
     control that opened it on close. Styled with WireKit tokens throughout. --}}
@php($delivery = $this->delivery)
<div class="wh-dash-drawer" wire:key="delivery-drawer">
    @if ($delivery !== null)
        <div
            x-data="{
                trigger: null,
                focusables() {
                    return Array.from(this.$refs.panel.querySelectorAll('a[href], button, input, select, textarea, [tabindex]')).filter((el) => ! el.disabled && el.tabIndex !== -1 && el.offsetParent !== null);
                },
                init() {
                    this.trigger = document.activeElement;
                    this.$nextTick(() => {
                        const targets = this.focusables();
                        (targets[0] ?? this.$refs.panel).focus();
                    });
                },
                destroy() {
                    if (this.trigger && typeof this.trigger.focus === 'function') {
                        this.trigger.focus();
                    }
                },
                trapTab(event) {
                    const targets = this.focusables();
                    if (targets.length === 0) {
                        event.preventDefault();
                        return;
                    }
                    const first = targets[0];
                    const last = targets[targets.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (! event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                },
            }"
            x-on:keydown.escape.window="$wire.close()"
            x-on:keydown.tab="trapTab($event)"
            class="fixed inset-0 z-[var(--z-wk-drawer)] flex justify-end"
            role="dialog"
            aria-modal="true"
            aria-label="{{ __('webhooks::dashboard.a11y.delivery_details') }}"
        >
            <button
                type="button"
                wire:click="close"
                class="absolute inset-0 bg-[var(--color-wk-overlay)]"
                aria-label="{{ __('webhooks::dashboard.a11y.close_details') }}"
            ></button>

            <div x-ref="panel" tabindex="-1" class="relative flex h-full w-full max-w-md flex-col overflow-y-auto bg-[var(--color-wk-bg-elevated)] p-[var(--padding-wk-x-lg)] shadow-[var(--shadow-wk-lg)]">
                <div class="mb-[var(--padding-wk-y-md)] flex items-start justify-between gap-[var(--padding-wk-x-md)]">
                    <x-wirekit::heading :level="2" size="md">{{ $delivery->event_type }}</x-wirekit::heading>
                    <x-wirekit::button size="sm" surface="ghost" wire:click="close" :aria-label="__('webhooks::dashboard.a11y.close_details')">{{ __('webhooks::dashboard.drawer.close') }}</x-wirekit::button>
                </div>

                @php($intent = match ($delivery->status->value) {
                    'succeeded' => 'success',
                    'failed', 'exhausted' => 'danger',
                    default => 'warning',
                })
                <div class="mb-[var(--padding-wk-y-md)] flex flex-wrap items-center gap-[var(--padding-wk-x-md)]">
                    <x-wirekit::badge :intent="$intent">{{ __('webhooks::dashboard.status.'.$delivery->status->value) }}</x-wirekit::badge>
                    <x-wirekit::text size="sm" variant="muted">{{ __('webhooks::dashboard.drawer.attempt', ['number' => $delivery->attempt]) }}</x-wirekit::text>
                    @if ($delivery->response_code !== null)
                        <x-wirekit::text size="sm" variant="muted">{{ __('webhooks::dashboard.drawer.http', ['code' => $delivery->response_code]) }}</x-wirekit::text>
                    @endif
                    @if ($delivery->duration_ms !== null)
                        <x-wirekit::text size="sm" variant="muted">{{ $delivery->duration_ms }} ms</x-wirekit::text>
                    @endif
                </div>

                {{-- Absolute timestamps in the reader's locale: this is the detail panel, where
                     an operator correlates the delivery against their own logs. --}}
                @php($locale = ['locale' => app()->getLocale()])
                <x-wirekit::timeline class="mb-[var(--padding-wk-y-md)]">
                    <x-wirekit::timeline.item :time="$delivery->created_at->settings($locale)->isoFormat('LLL')" variant="default">
                        {{ __('webhooks::dashboard.drawer.queued') }}
                    </x-wirekit::timeline.item>
                    @if ($delivery->delivered_at !== null)
                        <x-wirekit::timeline.item :time="$delivery->delivered_at->settings($locale)->isoFormat('LLL')" variant="success">
                            {{ __('webhooks::dashboard.drawer.delivered') }}
                        </x-wirekit::timeline.item>
                    @endif
                </x-wirekit::timeline>

                <x-wirekit::text size="sm" weight="medium">{{ __('webhooks::dashboard.drawer.payload') }}</x-wirekit::text>
                {{-- The WireKit code block, not a raw pre: it brings the copy button an operator
                     reaches for when pasting a body into a bug report. --}}
                <x-wirekit::code-block
                    language="json"
                    :copy="true"
                    class="wh-dash-drawer-payload mt-[var(--padding-wk-y-sm)]"
                >{{ json_encode($delivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</x-wirekit::code-block>

                <div class="mt-[var(--padding-wk-y-md)]">
                    {{-- Disabled while the replay is in flight, so a double-click cannot enqueue
                         the same delivery twice. --}}
                    <x-wirekit::button
                        wire:click="redeliver('{{ $delivery->id }}')"
                        wire:loading.attr="disabled"
                        wire:target="redeliver"
                        :aria-label="__('webhooks::dashboard.a11y.replay_delivery', ['event' => $delivery->event_type])"
                    >{{ __('webhooks::dashboard.drawer.replay') }}</x-wirekit::button>
                </div>
            </div>
        </div>
    @endif
</div>
