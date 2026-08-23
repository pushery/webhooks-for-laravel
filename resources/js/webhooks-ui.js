/*
 * The Alpine components the package's own screens mount, served as a FILE from the
 * application's own origin.
 *
 * WHY A FILE AND NOT AN INLINE SCRIPT. These factories used to be registered by an inline
 * <script> inside each view's @assets block, carrying an optional CSP nonce. Under a strict,
 * NONCE-LESS policy — `script-src 'self'`, which an application is entitled to choose — the
 * browser refuses to run it. Nothing throws, nothing reaches a server log, and a CSP audit
 * sees an expression that is grammatically perfect: the surface is simply dead. That was the
 * third distinct cause of the same dead panel, so the fix is the one that has no policy
 * dependency left. A file from 'self' runs under every policy, with no nonce, no
 * 'unsafe-inline', and nothing for the host to configure.
 *
 * WHY REGISTERED FACTORIES AND NOT INLINE EXPRESSIONS. Under a policy without 'unsafe-eval'
 * Alpine runs its CSP evaluator, which parses attribute expressions against a small grammar
 * rather than handing them to `new Function`. An object literal with methods does not parse
 * there, so an inline `x-data="{ … }"` would silently never run. A registered factory keeps
 * the attribute a bare call, and the body below is ordinary JavaScript that never meets that
 * evaluator at all.
 */
(function () {
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
