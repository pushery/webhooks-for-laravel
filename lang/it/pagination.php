<?php

declare(strict_types=1);

// Copy for the package's own pagination control (resources/views/pagination.blade.php),
// which every paginating shipped surface renders through paginationView(). It replaces
// Livewire's built-in pagination view, whose markup carries a hardcoded English
// accessible name and a raw color palette no design token reaches.
return [
    // The accessible name of the <nav> landmark wrapping the page controls.
    'navigation' => 'Paginazione',

    'previous' => 'Precedente',
    'next' => 'Successivo',

    // The result counter. Every number travels as a placeholder, so a locale is free to
    // put them wherever its grammar wants them.
    'summary' => 'Da :first a :last di :total risultati',
    'summary_of_unknown_total' => 'Da :first a :last',

    'filtered' => '{0} Nessun risultato.|{1} Un risultato.|[2,*] :count risultati.',
    'filtered_of_unknown_total' => '{0} Nessun risultato.|{1} Un risultato in questa pagina.|[2,*] :count risultati in questa pagina.',

    // Strings only a screen reader announces. An untranslated accessible name is an
    // untranslated interface.
    'a11y' => [
        'goto_page' => 'Vai alla pagina :page',
        'current_page' => 'Pagina :page',
    ],
];
