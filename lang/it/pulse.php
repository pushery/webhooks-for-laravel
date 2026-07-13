<?php

declare(strict_types=1);

// Copy for the Pulse card. The card renders inside Pulse's own dashboard and keeps
// Pulse's structure and components; only the strings this package contributes are
// translated. The period in :period is formatted by Pulse itself and arrives in
// whatever wording Pulse produces.
return [
    'card' => [
        'name' => 'Consegne webhook',
        // Pulse's convention for a card's timing tooltip. The already-formatted
        // duration arrives as one placeholder so a locale never has to reassemble the
        // number and its unit.
        'timing' => 'Tempo: :duration; Eseguito alle: :at;',
        'details' => 'ultimi :period',
    ],

    'metrics' => [
        'throughput' => 'Portata',
        'failure_rate' => 'Tasso di errori',
        'failed' => ':count non riuscite',
        'avg_latency' => 'Latenza media',
        'max_latency' => 'Latenza massima',
    ],

    'table' => [
        'event' => 'Evento',
        'count' => 'Conteggio',
        'failures' => 'Errori',
        'avg_max' => 'Media / Max',
    ],
];
