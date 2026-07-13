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

    // Strings only a screen reader announces. An untranslated accessible name is an
    // untranslated interface.
    'a11y' => [
        'goto_page' => 'Ir para a página :page',
        'current_page' => 'Página :page',
    ],
];
