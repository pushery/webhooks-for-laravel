<?php

declare(strict_types=1);

// Copy for the package's own pagination control (resources/views/pagination.blade.php),
// which every paginating shipped surface renders through paginationView().
return [
    // The accessible name of the <nav> landmark wrapping the page controls.
    'navigation' => 'Pagination',

    'previous' => 'Précédent',
    'next' => 'Suivant',

    // Every number travels as a placeholder, so a locale is free to put them wherever
    // its grammar wants them.
    'summary' => 'Affichage de :first à :last sur :total résultats',
    'summary_of_unknown_total' => 'Affichage de :first à :last',

    'filtered' => '{0} Aucun résultat.|{1} Un résultat.|[2,*] :count résultats.',
    'filtered_of_unknown_total' => '{0} Aucun résultat.|{1} Un résultat sur cette page.|[2,*] :count résultats sur cette page.',

    // Strings only a screen reader announces. An untranslated accessible name is an
    // untranslated interface.
    'a11y' => [
        'goto_page' => 'Aller à la page :page',
        'current_page' => 'Page :page',
    ],
];
