<?php

declare(strict_types=1);

// The two characters that decide how every number on every shipped screen reads. They live
// here, beside the date patterns, for the same reason those do: they are properties of the
// LANGUAGE, not of a screen, and a host that disagrees with one of them can publish the file
// and change it.
//
// Not `Illuminate\Support\Number`, and that is the whole reason this file exists. Every
// locale-aware method on that class calls `ensureIntlExtensionIsInstalled()` and throws when
// `ext-intl` is absent, and this package deliberately does not require that extension. Using it
// would turn wrong separators into a fatal error, on more screens than the bug was on.
// `IntlFreeNumberFormattingTest` holds both halves of that rule.
//
// The separators below are ICU's, read off the installed formatter rather than remembered:
//
//   php -r 'echo Illuminate\Support\Number::format(1234567.5, 1, locale: "<loc>");'
//
// en groups with a comma and separates the decimal with a period.
return [
    'thousands' => ',',
    'decimal' => '.',
];
