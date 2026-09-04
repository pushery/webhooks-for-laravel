<?php

declare(strict_types=1);

// Copy for the Pulse card. The card renders inside Pulse's own dashboard and keeps
// Pulse's structure and components; only the strings this package contributes are
// translated. The period in :period is formatted by Pulse itself and arrives in
// whatever wording Pulse produces.
return [
    'card' => [
        'name' => 'Entregas de webhook',
        // Pulse's convention for a card's timing tooltip. The already-formatted
        // duration arrives as one placeholder so a locale never has to reassemble the
        // number and its unit.
        'timing' => 'Tempo: :duration; Executado às: :at;',
        'details' => [
            // The whole phrase per period, rather than a determiner plus an interpolated noun:
            // agreement is decided by the noun and the noun arrives at run time, so a placeholder
            // cannot fix it. lang/en/pulse.php states the case in full.
            '6_hours' => 'últimas 6 horas',
            '24_hours' => 'últimas 24 horas',
            '7_days' => 'últimos 7 dias',
            'hour' => 'última hora',
        ],
    ],

    'metrics' => [
        'throughput' => 'Débito',
        'failure_rate' => 'Taxa de falhas',
        'failed' => ':count falhadas',
        'avg_latency' => 'Latência média',
        'max_latency' => 'Latência máx.',
    ],

    'table' => [
        'event' => 'Evento',
        'count' => 'Contagem',
        'failures' => 'Falhas',
        'avg_max' => 'Média / Máx',
    ],
];
