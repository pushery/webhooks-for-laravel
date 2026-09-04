{{-- Reveal and rotate an endpoint's signing secret. The value is shown only while the
     reveal window is open (enforced server-side by visibleCurrentSecret); an Alpine
     timer counts the TTL down visibly and auto-hides in the browser when it elapses.
     Rotation keeps the old secret as the verify-only rotation secret. Styled with
     WireKit tokens throughout.

     The timer is a registered component served as a file, rather than an inline expression and not
     an inline script — and both halves of that are load-bearing.

     Registered rather than inline EXPRESSION: under a Content-Security-Policy without
     `unsafe-eval`, Alpine parses attribute expressions against a small grammar rather than
     handing them to `new Function`, and an object literal with methods does not parse there
     — the countdown would silently never start and the secret would stay on screen past its
     window. Its inputs arrive through a data attribute rather than as an argument, so the
     expression stays a bare factory call and no `JSON.parse` appears in an Alpine
     expression, where `JSON` is not resolvable.

     A FILE rather than an inline SCRIPT: the registration used to ride in this block as an
     inline `<script>` with an optional nonce, and under a strict NONCE-LESS policy
     (`script-src 'self'`, which an application is entitled to choose) the browser simply
     refused to run it. Nothing threw, nothing reached a log, and a CSP audit read the
     expression as valid — the panel was dead and looked fine. Served from the app's own
     origin it runs under every policy, with nothing for the host to configure. --}}
@assets
    <script src="{{ \Pushery\Webhooks\Support\UiAssets::url() }}" defer></script>
@endassets
@php($secret = $this->visibleCurrentSecret)
@php($previous = $this->visiblePreviousSecret)
<div class="wh-portal-secret" wire:key="secret-panel">
    {{-- Persistent polite live region: survives the reveal card being torn down, so a
         screen reader still hears that the secret was withdrawn. --}}
    <x-wirekit::visually-hidden role="status" aria-live="polite">
        {{-- Both ends of the window, and NEITHER contains the key. This region announces that a
             secret appeared and that it went away again; the card itself announces nothing,
             because a live region wrapped around the card would read the key out loud on every
             unrelated change inside it. --}}
        @if ($hidden){{ __('webhooks::self-service.secret.hidden_announcement') }}@elseif ($secret !== null){{ __('webhooks::self-service.secret.shown_announcement') }}@endif
    </x-wirekit::visually-hidden>
    @if ($secret !== null)
        {{-- The countdown sentence and the impending-expiry cue are handed over as whole
             translated strings, so the timer speaks the reader's language without the view
             splitting a sentence into fragments a translator cannot reorder.

             The timer lives IN the Alpine component and is cleared in destroy(), never in a
             bare x-init: Alpine cleans up its own effects and listeners, but not a raw
             setInterval. Clicking Hide tears this card out of the DOM, and an interval left
             behind would keep ticking against a dead scope — calling $wire.hide() on a
             component that no longer exists, once per second, for every reveal. --}}
        {{-- The scope sits on our own element rather than on the card, and that is deliberate. The
             card sets an `x-data` of its own when its slot carries visible text with no card.body —
             a debug-only composition warning. This panel uses card.body, so that branch is not
             taken today and both scopes coexist; measured, with app.debug on, the card root carried
             exactly one x-data and it was ours.

             But HTML keeps the FIRST of two identical attributes. If that branch is ever
             taken — someone drops the card.body wrapper, or the component stops making the
             warning conditional — one of the two scopes silently stops existing, and the
             countdown that tells a reader how long the secret stays readable is the thing that
             disappears. No error, no log line. That is the same dead surface this panel has
             already had three times, from three different causes.

             Owning the element costs a div and removes the dependency entirely. --}}
        <div
            x-data="webhooksSecretCountdown()"
            data-webhooks-countdown="{{ json_encode([
                'remaining' => $this->remainingSeconds(),
                'countdown' => __('webhooks::self-service.secret.countdown'),
                'warning' => __('webhooks::self-service.secret.countdown_warning'),
            ], JSON_THROW_ON_ERROR) }}"
        >
        <x-wirekit::card>
            <x-wirekit::card.body>
                {{-- A plain region, NOT a live one, and that is the whole point of this line.
                     It used to be `role="status" aria-live="polite"` around the entire card
                     body -- and role="status" carries an implicit aria-atomic="true", so ANY
                     change inside it re-announced the WHOLE region: the heading, the endpoint
                     URL, the notice, the plaintext signing secret character by character, the
                     previous secret and every button. Pressing Copy is such a change, because
                     the button swaps its icon and its label. So was the ten-second expiry
                     warning. In an open-plan office or on a speaker, that reads a production
                     secret out loud without anyone asking for it.

                     It also nested live regions two deep -- the countdown's hidden region below
                     and the copy button's own `role="status"` -- which ARIA practice advises
                     against on its own.

                     The announcements live where they belong instead: the permanent hidden
                     region above says a secret was revealed, and the one below says the window
                     is closing. Both are small, both are atomic, and neither contains the key. --}}
                <div class="flex flex-col gap-[var(--padding-wk-y-md)]" role="region" aria-label="{{ __('webhooks::self-service.secret.region_label') }}">
                    <div class="flex flex-wrap items-start justify-between gap-[var(--padding-wk-x-md)]">
                        <x-wirekit::stack gap="none">
                            <x-wirekit::heading :level="3" size="sm">{{ __('webhooks::self-service.secret.heading') }}</x-wirekit::heading>
                            @if ($this->endpointUrl !== null)
                                <x-wirekit::text size="sm" intent="muted" class="break-all">{{ $this->endpointUrl }}</x-wirekit::text>
                            @endif
                        </x-wirekit::stack>
                        <x-wirekit::button size="sm" surface="ghost" intent="neutral" wire:click="hide">{{ __('webhooks::self-service.secret.hide') }}</x-wirekit::button>
                    </div>

                    <x-wirekit::text size="sm" intent="muted">
                        {{ __('webhooks::self-service.secret.notice') }}
                    </x-wirekit::text>

                    {{-- Visible countdown. aria-hidden so the per-second tick is not
                         announced; the impending-expiry cue is carried by the polite
                         live region below instead. tabular-nums sits on the whole line
                         (it only reshapes digits), so the seconds stop jittering wherever
                         a locale's grammar puts them. --}}
                    <x-wirekit::text
                        size="sm"
                        intent="muted"
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
        </div>
    @endif
</div>
