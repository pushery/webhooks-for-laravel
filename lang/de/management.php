<?php

declare(strict_types=1);

// Copy for the publishable management stubs (the neutral views and their WireKit
// twins). Both variants render the same screens, so they read the same keys — a
// label fixed here stays fixed in both, and a host that restyles one variant keeps
// the wording of the other.
return [
    'form' => [
        'name_label' => 'Name',
        'url_label' => 'Endpunkt-URL',
        // An example URL, not prose, but it reaches the reader as a placeholder, so a
        // locale can point it at a domain its audience recognizes.
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_legend' => 'Event-Typen',
        // The file path travels as a placeholder so a locale is free to put it wherever
        // its grammar wants it.
        'event_types_empty' => 'Konfiguriere die Event-Typen in :file.',
        'submit' => 'Endpunkt registrieren',
    ],

    'secret' => [
        'heading' => 'Signaturschlüssel (wird nur einmal angezeigt — speichere ihn jetzt)',
    ],

    'table' => [
        'endpoint' => 'Endpunkt',
        'events' => 'Events',
        'status' => 'Status',
        'event' => 'Event',
        'attempt' => 'Versuch',
        'code' => 'Code',
        'when' => 'Zeitpunkt',
        // Die Aktionsspalte zeigt keine sichtbare Überschrift, braucht aber trotzdem
        // einen barrierefreien Namen — er wird vorgelesen, also wird er übersetzt.
        'actions' => 'Aktionen',
    ],

    'subscription' => [
        'active' => 'Aktiv',
        'disabled' => 'Deaktiviert',
        'enable' => 'Aktivieren',
        'disable' => 'Deaktivieren',
        'delete' => 'Löschen',
    ],

    // Das Löschen eines Endpunkts ist unwiderruflich und stoppt eine laufende
    // Integration, deshalb bestätigen beide Stubs vorher — die WireKit-Variante mit
    // einem Alert-Dialog, die neutrale mit der Browser-Rückfrage.
    'delete_dialog' => [
        'title' => 'Diesen Endpunkt löschen?',
        'description' => 'Der Endpunkt empfängt ab sofort keine Webhooks mehr und sein Signaturschlüssel wird vernichtet. Das lässt sich nicht rückgängig machen.',
        'confirm' => 'Endpunkt löschen',
    ],

    'actions' => [
        'cancel' => 'Abbrechen',
    ],

    'empty' => [
        'no_subscriptions' => [
            'title' => 'Noch keine Endpunkte',
            'description' => 'Registriere oben deinen ersten Endpunkt, um Webhooks zuzustellen.',
        ],
        'no_deliveries' => [
            'title' => 'Keine Zustellungen gefunden',
            'description' => 'Sobald deine Events gesendet werden, erscheinen die Zustellungen hier. Entferne einen Filter, um mehr zu sehen.',
        ],
    ],

    'deliveries' => [
        'redeliver' => 'Erneut senden',
        'ping' => 'Test senden',
    ],

    'filters' => [
        // The filter controls hide their labels visually, so these strings reach
        // sighted readers only through assistive technology — they are translated for
        // exactly the same reason a visible label is.
        'status' => 'Status',
        'all_statuses' => 'Alle Status',
        'event_type' => 'Event-Typ',
        'event_type_placeholder' => 'Nach Event-Typ filtern',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. Lowercase, as
    // in the original design.
    'status' => [
        'pending' => 'ausstehend',
        'succeeded' => 'erfolgreich',
        'failed' => 'fehlgeschlagen',
        'exhausted' => 'aufgegeben',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'Ausstehend',
        'succeeded' => 'Erfolgreich',
        'failed' => 'Fehlgeschlagen',
        'exhausted' => 'Aufgegeben',
    ],

    'messages' => [
        // Wird gezeigt, wenn eine Zustellung an einen deaktivierten Endpunkt erneut
        // gesendet werden soll — deaktiviert von seinem Tenant oder vom Circuit Breaker.
        'endpoint_disabled' => 'Dieser Endpunkt ist deaktiviert. Aktiviere ihn wieder, bevor du eine Zustellung erneut sendest.',
    ],

    'validation' => [
        'url' => [
            // What the reader gets when the SSRF guard refuses the destination. The
            // guard's own message stays untranslated: it is an operator diagnostic for
            // the log, and it would tell a stranger which hosts resolve where.
            'blocked' => 'Diese URL kann nicht als Endpunkt verwendet werden. Verwende eine öffentlich erreichbare https-URL.',
        ],
    ],

    // Beschriftungen, die nur eine Vorlesesoftware ankündigt. Ein nicht übersetzter
    // barrierefreier Name ist eine nicht übersetzte Oberfläche.
    'a11y' => [
        'delete_subscription' => 'Endpunkt :url löschen',
    ],
];
