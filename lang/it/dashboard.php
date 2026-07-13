<?php

declare(strict_types=1);

return [
    // The browser window title for every dashboard page (the shared layout).
    'title' => 'Webhooks',

    'heading' => 'Webhooks',

    // Tab labels. The stored tab token is the array key and never changes; only the
    // label is translated — and it is stored display-ready, never cased by a CSS
    // text-transform the moment a host publishes and restyles the view.
    'tabs' => [
        'overview' => 'Panoramica',
        'webhooks' => 'Webhooks',
        'queue' => 'Coda',
        'documentation' => 'Documentazione',
    ],

    'kpis' => [
        'total' => 'Totale webhook inviati',
        'successful' => 'Riusciti',
        'failed' => 'Falliti',
        'pending' => 'In attesa',
        'retry_rate' => 'Tasso di nuovi tentativi',
    ],

    // Date patterns are translated, not just their month names: the ORDER differs by
    // locale (English leads with the month, Italian with the day).
    'formats' => [
        'hour_bucket' => 'j M H:00',
    ],

    'api' => [
        'unsupported_window' => 'L\'intervallo di metriche richiesto non è supportato. Intervalli supportati: :windows.',
        'invalid_window' => 'L\'intervallo selezionato non è valido. Intervalli supportati: :windows.',
    ],

    'activity' => [
        'title' => 'Attività oraria',
        'delivered' => 'Consegnate',
        'pending' => 'In attesa',
        'failed' => 'Fallite',
        'bar_title' => ':hour — :total in totale',
    ],

    'latency' => [
        'title' => 'Latenza (ms)',
        'p95_trend' => 'Andamento P95',
    ],

    'top_events' => [
        'title' => 'Eventi più frequenti',
    ],

    'recent' => [
        'title' => 'Coda recente',
    ],

    'setup' => [
        'title' => 'Endpoint',
        'total' => 'Totale',
        'active' => 'Attivi',
        'disabled' => 'Disattivati',
    ],

    'table' => [
        'event' => 'Evento',
        'status' => 'Stato',
        'attempt' => 'Tentativo',
        'code' => 'Codice',
        'duration' => 'Durata',
        'when' => 'Quando',
        'actions' => 'Azioni',
        'replay' => 'Reinvia',
    ],

    'filters' => [
        'status' => 'Stato',
        'all_statuses' => 'Tutti gli stati',
        'event_type' => 'Tipo di evento',
        'event_type_placeholder' => 'Filtra per tipo di evento',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. Lowercase, as
    // in the original design.
    'status' => [
        'pending' => 'in attesa',
        'succeeded' => 'riuscita',
        'failed' => 'fallita',
        'exhausted' => 'esaurita',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'In attesa',
        'succeeded' => 'Riuscita',
        'failed' => 'Fallita',
        'exhausted' => 'Esaurita',
    ],

    'drawer' => [
        'close' => 'Chiudi',
        'attempt' => 'Tentativo :number',
        'http' => 'HTTP :code',
        'queued' => 'In coda',
        'delivered' => 'Consegnata',
        'payload' => 'Payload',
        'replay' => 'Reinvia consegna',
    ],

    'empty' => [
        'no_activity' => [
            'title' => 'Ancora nessuna attività',
            'description' => 'Le consegne in questo intervallo compariranno qui, suddivise per ora.',
        ],
        'no_events' => [
            'title' => 'Ancora nessun evento',
            'description' => 'Qui i tuoi tipi di evento più frequenti vengono ordinati per numero.',
        ],
        'no_deliveries' => [
            'title' => 'Ancora nessuna consegna',
            'description' => 'Le consegne compariranno qui man mano che i tuoi eventi vengono inviati.',
        ],
        'no_deliveries_found' => [
            'title' => 'Nessuna consegna trovata',
            'description' => 'Nessuna consegna corrisponde ai filtri attuali. Rimuovi un filtro per vederne altre.',
        ],
        'no_endpoints' => [
            'title' => 'Nessun endpoint registrato',
            'description' => 'Registra un endpoint webhook per iniziare a ricevere consegne.',
        ],
    ],

    'docs' => [
        'title' => 'Documentazione',
        'body' => 'Registra gli endpoint, firma ogni consegna con lo schema Standard Webhooks e reinvia qualsiasi consegna da questa dashboard. Nel README del pacchetto trovi il riferimento completo alla configurazione e il catalogo degli eventi.',
    ],

    'toast' => [
        'redelivery_queued' => 'Reinvio in coda.',
        'endpoint_disabled' => 'Questo endpoint è disattivato. Riattivalo prima di reinviargli una consegna.',
    ],

    // Strings a reader never sees but a screen reader always announces. An
    // untranslated accessible name is an untranslated interface, so they live here
    // with the visible copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Vai al contenuto della dashboard',
        'time_window' => 'Intervallo di tempo',
        'sections' => 'Sezioni della dashboard',
        'retry_rate' => 'Tasso di nuovi tentativi',
        'deliveries_per_hour' => 'Consegne all\'ora',
        'hour_summary' => ':hour: :total in totale, :delivered consegnate, :pending in attesa, :failed fallite',
        'latency_trend' => 'Andamento della latenza P95 per ora',
        'recent_deliveries_table' => 'Consegne webhook recenti',
        'deliveries_table' => 'Consegne webhook',
        'replay_delivery' => 'Reinvia la consegna :event',
        'view_delivery' => 'Visualizza i dettagli della consegna :event',
        'delivery_details' => 'Dettagli della consegna',
        'close_details' => 'Chiudi i dettagli',
        'loading_kpis' => 'Caricamento delle metriche principali',
        'loading_chart' => 'Caricamento del grafico di attività',
        'loading_panel' => 'Caricamento del pannello',
        'loading_deliveries' => 'Caricamento delle consegne',
    ],
];
