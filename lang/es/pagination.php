<?php

declare(strict_types=1);

// Copy for the package's own pagination control (resources/views/pagination.blade.php),
// which every paginating shipped surface renders through paginationView().
return [
    // The accessible name of the <nav> landmark wrapping the page controls.
    'navigation' => 'Paginación',

    'previous' => 'Anterior',
    'next' => 'Siguiente',

    // Every number travels as a placeholder, so a locale is free to put them wherever
    // its grammar wants them.
    'summary' => 'Mostrando de :first a :last de :total resultados',
    'summary_of_unknown_total' => 'Mostrando de :first a :last',

    'filtered' => '{0} Sin resultados.|{1} Un resultado.|[2,*] :count resultados.',
    'filtered_of_unknown_total' => '{0} Sin resultados.|{1} Un resultado en esta página.|[2,*] :count resultados en esta página.',

    // Strings only a screen reader announces. An untranslated accessible name is an
    // untranslated interface.
    'a11y' => [
        'goto_page' => 'Ir a la página :page',
        'current_page' => 'Página :page',
    ],
];
