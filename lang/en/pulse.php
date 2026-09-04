<?php

declare(strict_types=1);

// Copy for the Pulse card. The card renders inside Pulse's own dashboard and keeps
// Pulse's structure and components; only the strings this package contributes are
// translated. The period in :period is formatted by Pulse itself and arrives in
// whatever wording Pulse produces.
return [
    'card' => [
        'name' => 'Webhook Deliveries',
        // Pulse's convention for a card's timing tooltip. The already-formatted
        // duration arrives as one placeholder so a locale never has to reassemble the
        // number and its unit.
        'timing' => 'Time: :duration; Run at: :at;',
        'details' => [
            // The whole phrase per period, rather than a determiner plus an interpolated noun. This
            // key used to read `'past :period'` and take its noun from Pulse's `periodForHumans()`,
            // which returns four hardcoded English strings — so the shipped card read "letzte 6
            // hours", "derniers hour", "últimos hour": half-translated on every non-English
            // installation, and the only such string in the package.
            //
            // The determiner was wrong too, in every locale at once. All seven chose a plural form,
            // and one of the four periods is singular, so even with the noun translated "derniers
            // heure" would still not agree. That is the shape a placeholder cannot fix: agreement
            // is decided by the noun, and the noun arrives at run time.
            //
            // Four whole phrases is what a translator can actually write, and it removes the
            // question rather than answering it per language.
            '6_hours' => 'past 6 hours',
            '24_hours' => 'past 24 hours',
            '7_days' => 'past 7 days',
            'hour' => 'past hour',
        ],
    ],

    'metrics' => [
        'throughput' => 'Throughput',
        'failure_rate' => 'Failure Rate',
        'failed' => ':count failed',
        'avg_latency' => 'Avg Latency',
        'max_latency' => 'Max Latency',
    ],

    'table' => [
        'event' => 'Event',
        'count' => 'Count',
        'failures' => 'Failures',
        'avg_max' => 'Avg / Max',
    ],
];
