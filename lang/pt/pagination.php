<?php

declare(strict_types=1);

// Copy for the package's own pagination control (resources/views/pagination.blade.php),
// which every paginating shipped surface renders through paginationView().
return [
    // The accessible name of the <nav> landmark wrapping the page controls.
    'navigation' => 'Paginação',

    'previous' => 'Anterior',
    'next' => 'Seguinte',

    // Every number travels as a placeholder, so a locale is free to put them wherever
    // its grammar wants them.
    'summary' => 'A mostrar :first a :last de :total resultados',
    'summary_of_unknown_total' => 'A mostrar :first a :last',

    'filtered' => '{0} Sem resultados.|{1} Um resultado.|[2,*] :count resultados.',
    'filtered_of_unknown_total' => '{0} Sem resultados.|{1} Um resultado nesta página.|[2,*] :count resultados nesta página.',

    // Strings only a screen reader announces. An untranslated accessible name is an
    // untranslated interface.
    'a11y' => [
        'goto_page' => 'Ir para a página :page',
        'current_page' => 'Página :page',
    ],
];
