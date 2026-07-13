{{-- Reveal and rotate an endpoint's signing secret. The value is shown only while the
     reveal window is open (enforced server-side by visibleCurrentSecret); an Alpine
     timer counts the TTL down visibly and auto-hides in the browser when it elapses.
     Rotation keeps the old secret as the verify-only rotation secret. Styled with
     WireKit tokens throughout. --}}
@php($secret = $this->visibleCurrentSecret)
@php($previous = $this->visiblePreviousSecret)
<div class="wh-portal-secret" wire:key="secret-panel">
    {{-- Persistent polite live region: survives the reveal card being torn down, so a
         screen reader still hears that the secret was withdrawn. --}}
    <x-wirekit::visually-hidden role="status" aria-live="polite">
        @if ($hidden){{ __('webhooks::self-service.secret.hidden_announcement') }}@endif
    </x-wirekit::visually-hidden>
    @if ($secret !== null)
        {{-- The countdown sentence and the impending-expiry cue are handed to Alpine as
             whole translated strings, so the timer speaks the reader's language without
             the view splitting a sentence into fragments a translator cannot reorder.
             Js::from quotes and escapes them into safe JS literals; it is echoed rather
             than written as @js because a component tag's attribute compiles echoes but
             not directives. --}}
        {{-- The timer lives IN the Alpine component and is cleared in destroy(), never in a
             bare x-init: Alpine cleans up its own effects and listeners, but not a raw
             setInterval. Clicking Hide tears this card out of the DOM, and an interval left
             behind would keep ticking against a dead scope — calling $wire.hide() on a
             component that no longer exists, once per second, for every reveal. --}}
        <x-wirekit::card
            x-data="{
                remaining: {{ $this->remainingSeconds() }},
                announce: '',
                tick: null,
                countdown: {{ \Illuminate\Support\Js::from(__('webhooks::self-service.secret.countdown')) }},
                warning: {{ \Illuminate\Support\Js::from(__('webhooks::self-service.secret.countdown_warning')) }},
                init() {
                    this.tick = setInterval(() => {
                        this.remaining = Math.max(0, this.remaining - 1);
                        if (this.remaining === 10) this.announce = this.warning;
                        if (this.remaining <= 0) { this.stop(); this.$wire.hide(); }
                    }, 1000);
                },
                stop() {
                    if (this.tick !== null) { clearInterval(this.tick); this.tick = null; }
                },
                destroy() {
                    this.stop();
                },
            }"
        >
            <x-wirekit::card.body>
                <div class="flex flex-col gap-[var(--padding-wk-y-md)]" role="status" aria-live="polite">
                    <div class="flex flex-wrap items-start justify-between gap-[var(--padding-wk-x-md)]">
                        <x-wirekit::stack gap="none">
                            <x-wirekit::heading :level="3" size="sm">{{ __('webhooks::self-service.secret.heading') }}</x-wirekit::heading>
                            @if ($this->endpointUrl !== null)
                                <x-wirekit::text size="sm" variant="muted" class="break-all">{{ $this->endpointUrl }}</x-wirekit::text>
                            @endif
                        </x-wirekit::stack>
                        <x-wirekit::button size="sm" surface="ghost" intent="neutral" wire:click="hide">{{ __('webhooks::self-service.secret.hide') }}</x-wirekit::button>
                    </div>

                    <x-wirekit::text size="sm" variant="muted">
                        {{ __('webhooks::self-service.secret.notice') }}
                    </x-wirekit::text>

                    {{-- Visible countdown. aria-hidden so the per-second tick is not
                         announced; the impending-expiry cue is carried by the polite
                         live region below instead. tabular-nums sits on the whole line
                         (it only reshapes digits), so the seconds stop jittering wherever
                         a locale's grammar puts them. --}}
                    <x-wirekit::text
                        size="sm"
                        variant="muted"
                        class="tabular-nums"
                        aria-hidden="true"
                        x-text="countdown.replace(':seconds', remaining)"
                    >{{ __('webhooks::self-service.secret.countdown', ['seconds' => $this->remainingSeconds()]) }}</x-wirekit::text>
                    <x-wirekit::visually-hidden aria-live="polite" x-text="announce" />

                    <div class="flex flex-wrap items-center gap-[var(--gap-wk-sm)]">
                        <x-wirekit::code class="wh-portal-secret-value break-all">{{ $secret }}</x-wirekit::code>
                        <x-wirekit::clipboard-button
                            :value="$secret"
                            :copied-text="__('webhooks::self-service.secret.copied')"
                        >{{ __('webhooks::self-service.secret.copy') }}</x-wirekit::clipboard-button>
                    </div>

                    @if ($previous !== null)
                        <div class="flex flex-col gap-[var(--padding-wk-y-sm)]">
                            <x-wirekit::text size="sm" weight="medium">{{ __('webhooks::self-service.secret.previous') }}</x-wirekit::text>
                            <div class="flex flex-wrap items-center gap-[var(--gap-wk-sm)]">
                                <x-wirekit::code class="wh-portal-previous-secret break-all">{{ $previous }}</x-wirekit::code>
                                <x-wirekit::clipboard-button
                                    :value="$previous"
                                    :copied-text="__('webhooks::self-service.secret.copied')"
                                >{{ __('webhooks::self-service.secret.copy') }}</x-wirekit::clipboard-button>
                            </div>
                        </div>
                    @endif

                    <div>
                        <x-wirekit::button surface="ghost" wire:click="rotate">{{ __('webhooks::self-service.secret.rotate') }}</x-wirekit::button>
                    </div>
                </div>
            </x-wirekit::card.body>
        </x-wirekit::card>
    @endif
</div>
