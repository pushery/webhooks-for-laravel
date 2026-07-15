<?php

declare(strict_types=1);

namespace Webhooks\Support;

use Closure;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Resolves the color scheme the package's own full-page layouts (the dashboard and the
 * self-service portal) render in.
 *
 * WireKit's dark tokens live behind a `.dark` class on the document root — they never
 * switch on the OS preference by themselves. Because these layouts are the package's,
 * not the host's, the host has no place to put that class: without this, every reader on
 * a dark-mode system gets a light interface with no supported way to opt out.
 *
 * The mode is a single host-facing switch:
 *
 *  - auto  (default) — mirror the operating system's preference onto the root element,
 *                      and keep mirroring it when the reader changes it.
 *  - light           — always light; no class, no inline script.
 *  - dark            — always dark; the class is rendered server-side.
 *
 * Pinning the mode is also the escape hatch for a host under a strict Content-Security
 * Policy: only 'auto' emits the inline mirroring script.
 */
final class UiTheme
{
    /**
     * The modes a host may configure. An unknown value resolves to 'auto' rather than
     * throwing: a mistyped presentation setting must never take a dashboard down.
     */
    public const array MODES = ['auto', 'light', 'dark'];

    /**
     * A registered per-request CSP-nonce source. Held here rather than in config because a
     * Closure in config/webhooks.php breaks `php artisan config:cache`.
     *
     * @var (Closure(): ?string)|null
     */
    private static ?Closure $nonceResolver = null;

    public static function mode(): string
    {
        $mode = Config::get('webhooks.ui.theme', 'auto');

        return is_string($mode) && in_array($mode, self::MODES, true) ? $mode : 'auto';
    }

    /**
     * The value for <meta name="color-scheme">, so form controls, scrollbars and the
     * canvas background match the interface before any stylesheet paints.
     */
    public static function colorScheme(): string
    {
        return match (self::mode()) {
            'light' => 'light',
            'dark' => 'dark',
            default => 'light dark',
        };
    }

    /**
     * Whether the document root must carry WireKit's `.dark` class from the server.
     */
    public static function forcesDark(): bool
    {
        return self::mode() === 'dark';
    }

    /**
     * Whether the layout mirrors the operating system's preference in the browser.
     */
    public static function mirrorsSystem(): bool
    {
        return self::mode() === 'auto';
    }

    /**
     * The Blade view a host points `webhooks.ui.assets` at to inject its own compiled
     * assets (its `@vite` tags) into the package layouts' <head>, or null to inject none —
     * so the shipped screens load the host's asset pipeline without forking the layout.
     */
    public static function assetsView(): ?string
    {
        $view = Config::get('webhooks.ui.assets');

        return is_string($view) && $view !== '' ? $view : null;
    }

    /**
     * Register a per-request source for the theme script's CSP nonce — call it from a service
     * provider's boot(): `UiTheme::resolveNonceUsing(fn () => Vite::cspNonce())`. This is the
     * per-request path; the config value is a STATIC string only, because a Closure placed in
     * config/webhooks.php makes `php artisan config:cache` throw (the config is not serializable)
     * — the exact deploy step a strict-CSP host runs.
     *
     * @param  Closure(): ?string  $resolver
     */
    public static function resolveNonceUsing(Closure $resolver): void
    {
        self::$nonceResolver = $resolver;
    }

    /**
     * Drop a registered nonce resolver, falling back to the config value (mainly for tests).
     */
    public static function forgetNonceResolver(): void
    {
        self::$nonceResolver = null;
    }

    /**
     * The CSP nonce for the inline theme script: the registered per-request resolver if one is
     * set, else the static `webhooks.ui.csp_nonce` string, else null (no nonce attribute is then
     * emitted). Only the 'auto' theme emits the script at all.
     */
    public static function nonce(): ?string
    {
        if (self::$nonceResolver instanceof Closure) {
            $resolved = (self::$nonceResolver)();

            return is_string($resolved) && $resolved !== '' ? $resolved : null;
        }

        $configured = Config::get('webhooks.ui.csp_nonce');

        if ($configured === null) {
            return null;
        }

        // A closure (or any non-string) in config can't be `config:cache`d — earlier releases
        // documented one here, so fail LOUD with the migration path rather than silently drop the
        // nonce (which would strip CSP protection from the theme script without a word).
        if (! is_string($configured)) {
            throw new InvalidArgumentException(
                'webhooks.ui.csp_nonce must be a static string (or null). A closure there breaks '
                .'`php artisan config:cache`; register a per-request nonce with '
                .'UiTheme::resolveNonceUsing(fn () => Vite::cspNonce()) from a service provider instead.'
            );
        }

        return $configured !== '' ? $configured : null;
    }

    /**
     * The ` nonce="…"` attribute for the inline theme script, HTML-escaped, or an empty
     * string when no nonce is configured. Rendered raw into the <script> tag.
     */
    public static function nonceAttribute(): string
    {
        $nonce = self::nonce();

        return $nonce === null ? '' : ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'"';
    }
}
