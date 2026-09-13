<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

/**
 * Holds a `wire:submit` form still until Livewire has bound it.
 *
 * ## The window
 *
 * A `<form wire:submit>` carries no `method`, so until Livewire has bound the component around it the
 * browser treats it as a plain GET form. Enter in that moment sends the page to its own address with every
 * named field in the query string, and from there into the browser history, the access log and the request
 * URL an error tracker records. The window opens on every page load and lasts as long as Livewire's scripts
 * take to arrive, which the package's layouts load at the end of the body.
 *
 * ## The guard
 *
 * One capture-phase listener in the head cancels the default submission of any form with a `wire:submit`
 * attribute. A bound form is unaffected: Livewire turns `wire:submit` into `x-on:submit.prevent`, and that
 * handler calls the action without asking whether the default was already prevented.
 *
 * ## Under a strict Content-Security-Policy
 *
 * The script is a constant, so its hash is the same on every response: {@see self::cspHash()} is the source
 * a host adds to `script-src`. The layouts put the configured theme nonce on it as well, but a nonce stops
 * matching the document's policy after `wire:navigate` and on a cached page, and a hash does not.
 */
final class LivewireSubmitGuard
{
    /** The script body, byte for byte what the hash covers. */
    public const string SCRIPT = "document.addEventListener('submit',function(e){var f=e.target;if(!(f instanceof HTMLFormElement)){return;}for(var i=0;i<f.attributes.length;i++){if(f.attributes[i].name.indexOf('wire:submit')===0){e.preventDefault();return;}}},true);";

    /** The CSP source that admits the script, for a host's `script-src`: `'sha256-…'`, quotes included. */
    public static function cspHash(): string
    {
        return "'sha256-".base64_encode(hash('sha256', self::SCRIPT, true))."'";
    }
}
