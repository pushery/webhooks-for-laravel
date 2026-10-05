<?php

declare(strict_types=1);

// Not `Illuminate\Support\Number`: its locale-aware methods throw without `ext-intl`, which this
// package deliberately does not require. lang/en/formats.php states the case in full.
return [
    'thousands' => '.',
    'decimal' => ',',
];
