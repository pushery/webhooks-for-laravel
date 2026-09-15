/*
 * The Alpine components the package's own screens mount, served as a file from the application's
 * own origin.
 *
 * A file rather than an inline script. These factories used to be registered by an inline <script>
 * inside each view's @assets block, carrying an optional CSP nonce. Under a strict, nonce-less
 * policy — `script-src 'self'`, which an application is entitled to choose — the browser refuses to
 * run it. Nothing throws, nothing reaches a server log, and a CSP audit sees an expression that is
 * grammatically perfect: the surface is simply dead. That was the third distinct cause of the same
 * dead panel, so the fix is the one that has no policy dependency left. A file from 'self' runs
 * under every policy, with no nonce, no 'unsafe-inline', and nothing for the host to configure.
 *
 * Registered factories rather than inline expressions. Under a policy without 'unsafe-eval' Alpine
 * runs its CSP evaluator, which parses attribute expressions against a small grammar instead of
 * handing them to `new Function`. An object literal with methods does not parse there, so an inline
 * `x-data="{ … }"` would silently never run. A registered factory keeps the attribute a bare call,
 * and the body below is ordinary JavaScript that never meets that evaluator at all.
 */
(function () {
    /*
     * The control a reader last pressed, for the two components below that give focus back to
     * whatever opened them.
     *
     * `document.activeElement` is not that control in every engine. macOS WebKit does not focus a
     * button on click: it focuses the nearest focusable ancestor instead, which on the deliveries
     * table is the table's scroll region. A drawer that remembered the active element remembered
     * that region and gave focus back to it on close. Blink and Linux WebKit focus the button itself,
     * which is why the lane stayed green while the same arm was red on every Mac.
     *
     * So the pressed control wins when nothing else has focus, when focus sits on an element that
     * CONTAINS the pressed control, or when the press is the newer of the two events. A keyboard
     * reader never presses, so their focus decides.
     */
    var lastPressed = null;
    var pressedAt = 0;
    var focusedAt = 0;
    document.addEventListener('pointerdown', function (event) {
        lastPressed = event.target instanceof Element
            ? event.target.closest('a[href], button, input, select, textarea, [tabindex]')
            : null;
        pressedAt = performance.now();
    }, true);
    document.addEventListener('focusin', function () {
        focusedAt = performance.now();
    }, true);
    var openedFrom = function () {
        var active = document.activeElement;
        var focused = active && active !== document.body ? active : null;
        var pressed = lastPressed && lastPressed.isConnected ? lastPressed : null;

        if (pressed && (! focused || focused.contains(pressed) || pressedAt > focusedAt)) {
            return pressed;
        }

        return focused;
    };

    var register = function () {
        /*
         * The reveal-window countdown on the self-service secret panel.
         *
         * Its inputs arrive through a data attribute rather than as arguments, so the Alpine
         * expression stays a bare factory call: `JSON` is not resolvable inside the CSP
         * evaluator's grammar, so a JSON.parse in the attribute would not parse either.
         *
         * The interval is cleared in destroy(). Alpine cleans up its own effects and
         * listeners but not a raw setInterval, and clicking Hide tears the card out of the
         * DOM — an interval left behind would keep calling $wire.hide() on a component that
         * no longer exists, once per second, for every reveal.
         */
        window.Alpine.data('webhooksSecretCountdown', function () {
            return {
                remaining: 0,
                announce: '',
                countdown: '',
                warning: '',
                tick: null,
                init() {
                    const config = JSON.parse(this.$el.dataset.webhooksCountdown ?? '{}');
                    this.remaining = config.remaining ?? 0;
                    this.countdown = config.countdown ?? '';
                    this.warning = config.warning ?? '';

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
            };
        });

        /*
         * The modal keyboard model for the dashboard's delivery drawer: focus moves into the
         * panel on open, Tab is trapped inside it, and focus returns to the control that
         * opened it on close. The drawer is a bespoke dialog rather than a component-library
         * one, because its open state is owned by Livewire — so the keyboard model it would
         * otherwise inherit has to be supplied here.
         */
        window.Alpine.data('webhooksFocusTrap', function () {
            return {
                trigger: null,
                focusables() {
                    return Array.from(this.$refs.panel.querySelectorAll('a[href], button, input, select, textarea, [tabindex]')).filter((el) => ! el.disabled && el.tabIndex !== -1 && el.offsetParent !== null);
                },
                init() {
                    this.trigger = openedFrom();
                    this.$nextTick(() => {
                        const targets = this.focusables();
                        (targets[0] ?? this.$refs.panel).focus();
                    });
                },
                destroy() {
                    if (this.trigger && this.trigger.isConnected && typeof this.trigger.focus === 'function') {
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
            };
        });

        /*
         * Focus and announcement for a panel Livewire opens IN PLACE.
         *
         * The endpoint form is not a dialog -- it appears inline, and in the page's markup it
         * sits BEFORE the list, so the new content is inserted above the button that asked for
         * it. Nothing moves, nothing scrolls and nothing is announced: a sighted reader looks
         * at the list where the button was; a screen-reader user is still after the trigger,
         * which is now after the whole form; a keyboard user tabs FORWARD out of the list
         * rather than into the thing they just opened.
         *
         * Deliberately NOT the focus trap above. This is not modal -- the list behind it stays
         * live and reachable, and trapping Tab in a panel a reader is meant to leave would be
         * worse than leaving it alone. What is needed is the move in, and the way back out on
         * close.
         */
        window.Alpine.data('webhooksOpenedPanel', function () {
            return {
                trigger: null,
                init() {
                    this.trigger = openedFrom();
                    this.$nextTick(() => {
                        const first = this.$el.querySelector('input, select, textarea, button');
                        (first ?? this.$el).focus();
                    });
                },
                destroy() {
                    // Back to the control that opened it, when it is still there. After a save
                    // the list re-renders and the trigger may be gone; focus then stays where
                    // the browser left it rather than jumping to the top of the document.
                    if (this.trigger && this.trigger.isConnected && typeof this.trigger.focus === 'function') {
                        this.trigger.focus();
                    }
                },
            };
        });
    };

    // Both orders happen, and which one occurs depends on how the host loads Alpine. A
    // deferred file can execute before or after Livewire's bundle, so neither branch is
    // theoretical — registering only on the event loses every page where Alpine is already
    // up, and registering only eagerly loses every page where it is not.
    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register);
    }
})();
