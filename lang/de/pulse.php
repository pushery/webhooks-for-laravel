<?php

declare(strict_types=1);

// Copy for the Pulse card. The card renders inside Pulse's own dashboard and keeps
// Pulse's structure and components; only the strings this package contributes are
// translated. The period in :period is formatted by Pulse itself and arrives in
// whatever wording Pulse produces.
return [
    'card' => [
        'name' => 'Webhook-Zustellungen',
        // Pulse's convention for a card's timing tooltip. The already-formatted
        // duration arrives as one placeholder so a locale never has to reassemble the
        // number and its unit.
        'timing' => 'Zeit: :duration; Ausgeführt um: :at;',
        'details' => [
            // The whole phrase per period, rather than a determiner plus an interpolated noun:
            // agreement is decided by the noun and the noun arrives at run time, so a placeholder
            // cannot fix it. lang/en/pulse.php states the case in full.
            '6_hours' => 'letzte 6 Stunden',
            '24_hours' => 'letzte 24 Stunden',
            '7_days' => 'letzte 7 Tage',
            'hour' => 'letzte Stunde',
        ],
    ],

    'metrics' => [
        'throughput' => 'Durchsatz',
        'failure_rate' => 'Fehlerquote',
        'failed' => '{0} :count fehlgeschlagen|{1} :count fehlgeschlagen|[2,*] :count fehlgeschlagen',
        'refused' => '{0} :count abgelehnt|{1} :count abgelehnt|[2,*] :count abgelehnt',
        'avg_latency' => 'Ø Latenz',
        'max_latency' => 'Max. Latenz',
    ],

    'table' => [
        'event' => 'Event',
        'count' => 'Anzahl',
        'failures' => 'Fehler',
        'avg_max' => 'Ø / Max',
    ],
];
