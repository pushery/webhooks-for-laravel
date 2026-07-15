<?php

declare(strict_types=1);

namespace Webhooks\Support;

use Illuminate\Support\Facades\Config;

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
     * The CSP nonce for the inline theme script, from `webhooks.ui.csp_nonce` — a string or
     * a per-request callable (e.g. fn () => Vite::cspNonce()) — or null when none is set, in
     * which case no nonce attribute is emitted. Only the 'auto' theme emits the script at all.
     */
    public static function nonce(): ?string
    {
        $nonce = Config::get('webhooks.ui.csp_nonce');

        if (is_callable($nonce)) {
            $nonce = $nonce();
        }

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
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
