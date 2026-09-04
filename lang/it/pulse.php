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
        'details' => [
            // The whole phrase per period, rather than a determiner plus an interpolated noun:
            // agreement is decided by the noun and the noun arrives at run time, so a placeholder
            // cannot fix it. lang/en/pulse.php states the case in full.
            '6_hours' => 'ultime 6 ore',
            '24_hours' => 'ultime 24 ore',
            '7_days' => 'ultimi 7 giorni',
            'hour' => 'ultima ora',
        ],
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
