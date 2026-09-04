<?php

declare(strict_types=1);

// Beschriftungen der paketeigenen Blätterung (resources/views/pagination.blade.php), die
// jede blätternde Oberfläche über paginationView() rendert.
return [
    'navigation' => 'Blättern',

    'previous' => 'Zurück',
    'next' => 'Weiter',

    // Jede Zahl reist als Platzhalter, damit die Sprache sie dort platzieren kann, wo
    // ihre Grammatik sie erwartet.
    'summary' => ':first bis :last von :total Einträgen',
    // Dieselbe Zählung für die einfache Blätterung, die nie eine Gesamtzahl ermittelt hat.
    'summary_of_unknown_total' => ':first bis :last',

    'filtered' => '{0} Keine Treffer.|{1} Ein Treffer.|[2,*] :count Treffer.',
    'filtered_of_unknown_total' => '{0} Keine Treffer.|{1} Ein Treffer auf dieser Seite.|[2,*] :count Treffer auf dieser Seite.',

    'a11y' => [
        'goto_page' => 'Zu Seite :page',
        'current_page' => 'Seite :page',
    ],
];
