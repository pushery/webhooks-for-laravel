<?php

declare(strict_types=1);

// Copy for the Pulse card. The card renders inside Pulse's own dashboard and keeps
// Pulse's structure and components; only the strings this package contributes are
// translated. The period in :period is formatted by Pulse itself and arrives in
// whatever wording Pulse produces.
return [
    'card' => [
        'name' => 'Livraisons de webhooks',
        // Pulse's convention for a card's timing tooltip. The already-formatted
        // duration arrives as one placeholder so a locale never has to reassemble the
        // number and its unit.
        'timing' => 'Durée : :duration ; Exécuté à : :at ;',
        'details' => 'derniers :period',
    ],

    'metrics' => [
        'throughput' => 'Débit',
        'failure_rate' => 'Taux d\'échec',
        'failed' => ':count en échec',
        'avg_latency' => 'Latence moy.',
        'max_latency' => 'Latence max.',
    ],

    'table' => [
        'event' => 'Événement',
        'count' => 'Nombre',
        'failures' => 'Échecs',
        'avg_max' => 'Moy. / Max',
    ],
];
