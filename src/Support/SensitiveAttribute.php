<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\View\ComponentAttributeBag;

/**
 * The attribute a host names, in `ui.sensitive_attribute`, to mark every element that shows a
 * plaintext secret.
 *
 * A screenshot taken while a secret is on screen carries it into a bug report, a mail and an
 * inbox. A redactor such as visual-feedback's blanks the regions that carry an attribute of the
 * host's choosing, `data-visual-feedback-redact` there, so the panels put that attribute on the
 * value itself and leave the heading, the notice and the buttons readable. Only a `data-*` name
 * is taken: it cannot collide with an attribute that means something to the browser, and WireKit
 * passes it through. Anything else marks nothing.
 *
 * @internal
 */
final class SensitiveAttribute
{
    /** The configured name, or null where none or no `data-*` name is set. */
    public static function name(mixed $configured): ?string
    {
        if (! is_string($configured) || preg_match('/^data-[a-z0-9]+(?:-[a-z0-9]+)*$/', $configured) !== 1) {
            return null;
        }

        return $configured;
    }

    /** The attributes the element that shows a secret carries, none where no name is set. */
    public static function bag(): ComponentAttributeBag
    {
        $name = self::name(config('webhooks.ui.sensitive_attribute'));

        return new ComponentAttributeBag($name === null ? [] : [$name => true]);
    }
}
