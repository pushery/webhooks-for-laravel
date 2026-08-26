{{-- Delivery detail drawer. The open/close state is owned by Livewire (the panel is
     only in the DOM when a delivery is selected) rather than WireKit drawer's
     Alpine-only toggle, so the cross-component open from the table stays
     deterministic and server-testable. A small Alpine layer supplies the modal
     keyboard model the bespoke dialog would otherwise lack: focus moves into the panel
     on open, Tab is trapped inside it, Escape closes it, and focus returns to the
     control that opened it on close. Styled with WireKit tokens throughout.

     THE KEYBOARD MODEL IS A REGISTERED COMPONENT, NOT AN INLINE EXPRESSION, AND THAT IS
     LOAD-BEARING. Under a Content-Security-Policy without `unsafe-eval` a host runs
     Alpine's CSP evaluator, which parses attribute expressions against a small grammar
     instead of handing them to `new Function`. An object literal with methods does not
     parse there, so an inline trap would simply not run — in the browser, with nothing in
     any server log. It would take out the focus trap specifically, on a panel that shows
     delivery payloads, which is the one part of this screen a keyboard or screen-reader
     user cannot do without. A registered factory parses, and its body is ordinary
     JavaScript that never goes through that evaluator at all.

     The registration rides in `@assets`: Livewire injects the tag once per page into the
     head and de-duplicates it by compile key, so this needs no layout hook and no publish
     step, and it travels with the view when a host publishes it.

     ⚠️ IT IS A FILE FROM THE APP'S OWN ORIGIN, NOT AN INLINE SCRIPT, AND THAT REPLACED AN
     EARLIER CHOICE MADE HERE. The registration used to be inline, carrying an OPTIONAL CSP
     nonce. Under a strict NONCE-LESS policy — `script-src 'self'`, which an application is
     entitled to choose and which this package must not ask it to loosen — the browser
     refuses an inline script outright. Nothing throws, nothing reaches a log, and a CSP
     audit reads the expression as valid: the drawer's keyboard model is simply gone, on the
     one panel a keyboard or screen-reader user cannot do without. Served as a file it runs
     under every policy, needs no nonce, and asks the host for nothing. --}}
@assets
    <script src="{{ \Pushery\Webhooks\Support\UiAssets::url() }}" defer></script>
@endassets
@php($delivery = $this->delivery)
<div class="wh-dash-drawer" wire:key="delivery-drawer">
    @if ($delivery !== null)
        <div
            x-data="webhooksFocusTrap()"
            x-on:keydown.escape.window="$wire.close()"
            x-on:keydown.tab="trapTab($event)"
            class="fixed inset-0 z-[var(--z-wk-drawer)] flex justify-end"
            role="dialog"
            aria-modal="true"
            aria-label="{{ __('webhooks::dashboard.a11y.delivery_details') }}"
        >
            {{-- cursor-pointer, and it is load-bearing rather than cosmetic. Tailwind v4's
                 preflight gives buttons `cursor: default`, so without it the overlay reads as
                 dead space — and the pointer is the ONLY feedback an overlay can give: it has
                 no border, no label and no focus ring. This is the drawer a reader clicks
                 through many deliveries in, asking "how do I get out of here" each time; the
                 other way out is Escape, which not everyone knows. An element that answers a
                 click without offering one is the one combination that is wrong in both
                 directions.

                 Native rather than the library's button, and deliberately: a full-bleed
                 invisible overlay is not a design-system control. It has no label, no surface
                 and no focus ring by design, which is the opposite of what that component is
                 for. --}}
            <button
                type="button"
                wire:click="close"
                class="absolute inset-0 cursor-pointer bg-[var(--color-wk-overlay)]"
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
                    <x-wirekit::text size="sm" intent="muted">{{ __('webhooks::dashboard.drawer.attempt', ['number' => $delivery->attempt]) }}</x-wirekit::text>
                    @if ($delivery->response_code !== null)
                        <x-wirekit::text size="sm" intent="muted">{{ __('webhooks::dashboard.drawer.http', ['code' => $delivery->response_code]) }}</x-wirekit::text>
                    @endif
                    @if ($delivery->duration_ms !== null)
                        <x-wirekit::text size="sm" intent="muted">{{ $delivery->duration_ms }} ms</x-wirekit::text>
                    @endif
                </div>

                {{-- Absolute timestamps in the reader's locale, WITH their zone: this is the
                     detail panel, where an operator correlates the delivery against their own
                     records. A bare wall-clock time cannot be correlated — the reader has no
                     way to tell whether it is theirs, and an hour of offset here reads as a
                     delivery that did not happen when it did.

                     And in the DISPLAY zone, which is not necessarily the application's:
                     app.timezone is one process-wide setting, so in a multi-tenant back-office
                     it is UTC for storage while the operator reading this sits somewhere else.
                     Unset, DashboardTimezone hands the value straight back — see the seam for
                     why the label alone was not enough. --}}
                @php($locale = ['locale' => app()->getLocale()])
                @php($zone = \Pushery\Webhooks\Dashboard\DashboardTimezone::apply(...))
                <x-wirekit::timeline class="mb-[var(--padding-wk-y-md)]">
                    <x-wirekit::timeline.item :time="$zone($delivery->created_at)->settings($locale)->isoFormat(__('webhooks::dashboard.formats.absolute'))" intent="default">
                        {{ __('webhooks::dashboard.drawer.queued') }}
                    </x-wirekit::timeline.item>
                    @if ($delivery->delivered_at !== null)
                        <x-wirekit::timeline.item :time="$zone($delivery->delivered_at)->settings($locale)->isoFormat(__('webhooks::dashboard.formats.absolute'))" intent="success">
                            {{ __('webhooks::dashboard.drawer.delivered') }}
                        </x-wirekit::timeline.item>
                    @endif
                </x-wirekit::timeline>

                <x-wirekit::text size="sm" weight="medium">{{ __('webhooks::dashboard.drawer.payload') }}</x-wirekit::text>
                {{-- The body is gated separately from the rest of the drawer, and the component
                     decides how much of it exists at all — see Pushery\Webhooks\Dashboard\PayloadVisibility.
                     $this->payloadJson is a COMPUTED property, so a body this user may not see is
                     never serialized into the Livewire snapshot; the check below is a boundary,
                     not a curtain. Never bind the delivery or its payload as a public property. --}}
                @if ($this->payloadOffloadNotice !== null)
                    {{-- FIRST, because it changes how everything below reads. Past the offload
                         threshold the row holds only a stub, so the largest deliveries render as
                         the smallest ones — and under a redacted body the stub is
                         indistinguishable from "there was barely anything here". --}}
                    <x-wirekit::text size="sm" intent="muted" class="mt-[var(--padding-wk-y-sm)] wh-dash-drawer-payload-offloaded">
                        {{ $this->payloadOffloadNotice }}
                    </x-wirekit::text>
                @endif

                @if ($this->payloadNotice !== null)
                    {{-- Say WHY, always. A panel that just stops after its heading reads as a
                         defect, and the next person "fixes" it by deleting the guard. --}}
                    <x-wirekit::text size="sm" intent="muted" class="mt-[var(--padding-wk-y-sm)] wh-dash-drawer-payload-notice">
                        {{ $this->payloadNotice }}
                    </x-wirekit::text>
                @endif

                @if ($this->payloadJson !== null)
                    {{-- The WireKit code block, not a raw pre: it brings the copy button an operator
                         reaches for when pasting a body into a bug report. --}}
                    <x-wirekit::code-block
                        language="json"
                        :copy="true"
                        class="wh-dash-drawer-payload mt-[var(--padding-wk-y-sm)]"
                    >{{ $this->payloadJson }}</x-wirekit::code-block>
                @endif

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
