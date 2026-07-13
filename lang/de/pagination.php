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

    'a11y' => [
        'goto_page' => 'Zu Seite :page',
        'current_page' => 'Seite :page',
    ],
];
