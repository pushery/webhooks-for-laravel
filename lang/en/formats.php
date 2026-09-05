<?php

declare(strict_types=1);

// The two characters that decide how every number on every shipped screen reads. They live in a
// file of their own rather than on any screen's, because they are properties of the LANGUAGE and
// not of a surface — a host that disagrees with one of them publishes this file and changes it
// once, for everything.
//
// Date patterns are per-language as well and are NOT here, which this comment claimed for a
// while. Each surface carries its own under its own `formats` KEY — the dashboard's are in
// dashboard.php — because which pattern a column wants depends on the column. The two names
// collide, so: this file is the numbers.
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
