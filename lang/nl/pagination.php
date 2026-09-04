<?php

declare(strict_types=1);

// Copy for the package's own pagination control (resources/views/pagination.blade.php),
// which every paginating shipped surface renders through paginationView(). It replaces
// Livewire's built-in pagination view, whose markup carries a hardcoded English
// accessible name and a raw color palette no design token reaches.
return [
    // The accessible name of the <nav> landmark wrapping the page controls.
    'navigation' => 'Paginering',

    'previous' => 'Vorige',
    'next' => 'Volgende',

    // The result counter. Every number travels as a placeholder, so a locale is free to
    // put them wherever its grammar wants them.
    'summary' => ':first tot :last van :total resultaten',
    'summary_of_unknown_total' => ':first tot :last',

    'filtered' => '{0} Geen resultaten.|{1} Eén resultaat.|[2,*] :count resultaten.',
    'filtered_of_unknown_total' => '{0} Geen resultaten.|{1} Eén resultaat op deze pagina.|[2,*] :count resultaten op deze pagina.',

    // Strings only a screen reader announces. An untranslated accessible name is an
    // untranslated interface.
    'a11y' => [
        'goto_page' => 'Ga naar pagina :page',
        'current_page' => 'Pagina :page',
    ],
];
