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
        'overview' => 'Overzicht',
        'webhooks' => 'Webhooks',
        'queue' => 'Wachtrij',
        'documentation' => 'Documentatie',
    ],

    'kpis' => [
        'total' => 'Totaal verzonden webhooks',
        'successful' => 'Geslaagd',
        'failed' => 'Mislukt',
        'pending' => 'In afwachting',
        'retry_rate' => 'Herhaalpercentage',
    ],

    // Date patterns are translated, not just their month names: the ORDER differs by
    // locale (English leads with the month, Dutch with the day).
    'formats' => [
        'hour_bucket' => 'j M H:00',
    ],

    'api' => [
        'unsupported_window' => 'Het opgevraagde metrics-venster wordt niet ondersteund. Ondersteunde vensters: :windows.',
        'invalid_window' => 'Het geselecteerde venster is ongeldig. Ondersteunde vensters: :windows.',
    ],

    'activity' => [
        'title' => 'Activiteit per uur',
        'delivered' => 'Afgeleverd',
        'pending' => 'In afwachting',
        'failed' => 'Mislukt',
        'bar_title' => ':hour — :total totaal',
    ],

    'latency' => [
        'title' => 'Latentie (ms)',
        'p95_trend' => 'P95-trend',
    ],

    'top_events' => [
        'title' => 'Belangrijkste events',
    ],

    'recent' => [
        'title' => 'Recente wachtrij',
    ],

    'setup' => [
        'title' => 'Endpoints',
        'total' => 'Totaal',
        'active' => 'Actief',
        'disabled' => 'Uitgeschakeld',
    ],

    'table' => [
        'event' => 'Event',
        'status' => 'Status',
        'attempt' => 'Poging',
        'code' => 'Code',
        'duration' => 'Duur',
        'when' => 'Wanneer',
        'actions' => 'Acties',
        'replay' => 'Opnieuw versturen',
    ],

    'filters' => [
        'status' => 'Status',
        'all_statuses' => 'Alle statussen',
        'event_type' => 'Event-type',
        'event_type_placeholder' => 'Filteren op event-type',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. Lowercase, as
    // in the original design.
    'status' => [
        'pending' => 'in afwachting',
        'succeeded' => 'geslaagd',
        'failed' => 'mislukt',
        'exhausted' => 'uitgeput',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'In afwachting',
        'succeeded' => 'Geslaagd',
        'failed' => 'Mislukt',
        'exhausted' => 'Uitgeput',
    ],

    'drawer' => [
        'close' => 'Sluiten',
        'attempt' => 'Poging :number',
        'http' => 'HTTP :code',
        'queued' => 'In wachtrij',
        'delivered' => 'Afgeleverd',
        'payload' => 'Payload',
        'replay' => 'Levering opnieuw versturen',
    ],

    'empty' => [
        'no_activity' => [
            'title' => 'Nog geen activiteit',
            'description' => 'Leveringen in dit venster verschijnen hier als uitsplitsing per uur.',
        ],
        'no_events' => [
            'title' => 'Nog geen events',
            'description' => 'Hier zie je jouw meest voorkomende event-types, gerangschikt op aantal.',
        ],
        'no_deliveries' => [
            'title' => 'Nog geen leveringen',
            'description' => 'Zodra jouw events worden verzonden, verschijnen de leveringen hier.',
        ],
        'no_deliveries_found' => [
            'title' => 'Geen leveringen gevonden',
            'description' => 'Geen enkele levering komt overeen met de huidige filters. Verwijder een filter om meer te zien.',
        ],
        'no_endpoints' => [
            'title' => 'Geen endpoints geregistreerd',
            'description' => 'Registreer een webhook-endpoint om leveringen te ontvangen.',
        ],
    ],

    'docs' => [
        'title' => 'Documentatie',
        'body' => 'Registreer endpoints, onderteken elke levering volgens het Standard Webhooks-schema en verstuur elke levering opnieuw vanuit dit dashboard. De volledige configuratiereferentie en de event-catalogus vind je in de README van het pakket.',
    ],

    'toast' => [
        'redelivery_queued' => 'Herlevering in de wachtrij geplaatst.',
        'endpoint_disabled' => 'Dit endpoint is uitgeschakeld. Schakel het weer in voordat je een levering opnieuw verstuurt.',
    ],

    // Strings a reader never sees but a screen reader always announces. An
    // untranslated accessible name is an untranslated interface, so they live here
    // with the visible copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Direct naar de dashboardinhoud',
        'time_window' => 'Tijdvenster',
        'sections' => 'Dashboardsecties',
        'retry_rate' => 'Herhaalpercentage',
        'deliveries_per_hour' => 'Leveringen per uur',
        'hour_summary' => ':hour: :total totaal, :delivered afgeleverd, :pending in afwachting, :failed mislukt',
        'latency_trend' => 'P95-latentietrend per uur',
        'recent_deliveries_table' => 'Recente webhook-leveringen',
        'deliveries_table' => 'Webhook-leveringen',
        'replay_delivery' => 'Levering :event opnieuw versturen',
        'view_delivery' => 'Details van levering :event bekijken',
        'delivery_details' => 'Leveringsdetails',
        'close_details' => 'Details sluiten',
        'loading_kpis' => 'Kerncijfers worden geladen',
        'loading_chart' => 'Activiteitsdiagram wordt geladen',
        'loading_panel' => 'Paneel wordt geladen',
        'loading_deliveries' => 'Leveringen worden geladen',
    ],
];
