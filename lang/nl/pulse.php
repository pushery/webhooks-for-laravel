<?php

declare(strict_types=1);

// Copy for the Pulse card. The card renders inside Pulse's own dashboard and keeps
// Pulse's structure and components; only the strings this package contributes are
// translated. The period in :period is formatted by Pulse itself and arrives in
// whatever wording Pulse produces.
return [
    'card' => [
        'name' => 'Webhook-leveringen',
        // Pulse's convention for a card's timing tooltip. The already-formatted
        // duration arrives as one placeholder so a locale never has to reassemble the
        // number and its unit.
        'timing' => 'Tijd: :duration; Uitgevoerd om: :at;',
        'details' => 'afgelopen :period',
    ],

    'metrics' => [
        'throughput' => 'Doorvoer',
        'failure_rate' => 'Foutpercentage',
        'failed' => ':count mislukt',
        'avg_latency' => 'Gem. latentie',
        'max_latency' => 'Max. latentie',
    ],

    'table' => [
        'event' => 'Event',
        'count' => 'Aantal',
        'failures' => 'Fouten',
        'avg_max' => 'Gem. / Max',
    ],
];
