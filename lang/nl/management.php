<?php

declare(strict_types=1);

// Copy for the publishable management stubs (the neutral views and their WireKit
// twins). Both variants render the same screens, so they read the same keys — a
// label fixed here stays fixed in both, and a host that restyles one variant keeps
// the wording of the other.
return [
    'form' => [
        'name_label' => 'Naam',
        'url_label' => 'Endpoint-URL',
        // An example URL, not prose, but it reaches the reader as a placeholder, so a
        // locale can point it at a domain its audience recognizes.
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_legend' => 'Event-types',
        // The file path travels as a placeholder so a locale is free to put it wherever
        // its grammar wants it.
        'event_types_empty' => 'Configureer de event-types in :file.',
        'submit' => 'Endpoint registreren',
    ],

    'secret' => [
        'heading' => 'Ondertekeningssleutel (wordt maar één keer getoond — sla hem nu op)',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'events' => 'Events',
        'status' => 'Status',
        'event' => 'Event',
        'attempt' => 'Poging',
        'code' => 'Code',
        'when' => 'Wanneer',
        // The actions column shows no visible header, but a column still needs an
        // accessible name — it is read out, so it is translated.
        'actions' => 'Acties',
    ],

    'subscription' => [
        'active' => 'Actief',
        'disabled' => 'Uitgeschakeld',
        'enable' => 'Inschakelen',
        'disable' => 'Uitschakelen',
        'delete' => 'Verwijderen',
    ],

    // Deleting an endpoint is irreversible and stops a live integration, so both stubs
    // confirm it first — the WireKit variant through an alert-dialog, the neutral one
    // through the browser confirm.
    'delete_dialog' => [
        'title' => 'Dit endpoint verwijderen?',
        'description' => 'Het endpoint ontvangt vanaf nu geen webhooks meer en de ondertekeningssleutel wordt vernietigd. Dit kan niet ongedaan worden gemaakt.',
        'confirm' => 'Endpoint verwijderen',
    ],

    'actions' => [
        'cancel' => 'Annuleren',
    ],

    'empty' => [
        'no_subscriptions' => [
            'title' => 'Nog geen endpoints',
            'description' => 'Registreer hierboven je eerste endpoint om webhooks af te leveren.',
        ],
        'no_deliveries' => [
            'title' => 'Geen leveringen gevonden',
            'description' => 'Zodra je events worden verzonden, verschijnen de leveringen hier. Verwijder een filter om meer te zien.',
        ],
    ],

    'deliveries' => [
        'redeliver' => 'Opnieuw versturen',
        'ping' => 'Test versturen',
    ],

    'filters' => [
        // The filter controls hide their labels visually, so these strings reach
        // sighted readers only through assistive technology — they are translated for
        // exactly the same reason a visible label is.
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

    'messages' => [
        // Shown when a replay is asked for an endpoint that is switched off — by its
        // tenant, or by the circuit breaker after too many failures.
        'endpoint_disabled' => 'Dit endpoint is uitgeschakeld. Schakel het weer in voordat je een levering opnieuw verstuurt.',
    ],

    'validation' => [
        'url' => [
            // What the reader gets when the SSRF guard refuses the destination. The
            // guard's own message stays untranslated: it is an operator diagnostic for
            // the log, and it would tell a stranger which hosts resolve where.
            'blocked' => 'Deze URL kan niet als endpoint worden gebruikt. Gebruik een openbaar bereikbare https-URL.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface.
    'a11y' => [
        'delete_subscription' => 'Endpoint :url verwijderen',
    ],
];
