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
        // Dasselbe Formular, sobald ein Endpunkt zum Bearbeiten geöffnet ist.
        'submit_update' => 'Änderungen speichern',
        // Nur beim Bearbeiten angeboten: eine Registrierung ist per Definition aktiv.
        'active_label' => 'Aktiv',
    ],

    'secret' => [
        'heading' => 'Signaturschlüssel (wird nur einmal angezeigt — speichere ihn jetzt)',
        // Eine Rotation sagt etwas, was eine Registrierung nicht sagt, und der Leser muss
        // es erfahren: der bisherige Schlüssel bleibt gültig, bis das Rotationsfenster
        // schließt. Genau deshalb ist eine Rotation im Störfall sofort machbar.
        'rotated_heading' => 'Neuer Signaturschlüssel (wird nur einmal angezeigt — speichere ihn jetzt). Der bisherige Schlüssel bleibt gültig, bis das Rotationsfenster schließt.',
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
        'edit' => 'Bearbeiten',
        'rotate' => 'Schlüssel rotieren',
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

    // Eine Rotation setzt den bisherigen Schlüssel unter eine Frist, statt ihn sofort
    // ungültig zu machen — sie bleibt aber eine Änderung, der jeder Empfänger folgen
    // muss. Deshalb bestätigen beide Stubs sie, wie das Löschen daneben.
    'rotate_dialog' => [
        'title' => 'Diesen Signaturschlüssel rotieren?',
        'description' => 'Ein neuer Schlüssel wird sofort erzeugt und einmal angezeigt. Der bisherige bleibt gültig, bis das Rotationsfenster schließt — aktualisiere den Empfänger bis dahin.',
        'confirm' => 'Schlüssel rotieren',
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

        // Erscheint, wenn ein Test-Ping abgelehnt wird, weil der Endpunkt sein Kontingent
        // für die Minute aufgebraucht hat. Die Wartezeit reist als Platzhalter.
        'ping_throttled' => 'Dieser Endpunkt hat seine Test-Pings vorerst aufgebraucht. Versuche es in :seconds Sekunde(n) erneut.',
    ],

    'validation' => [
        'event_types' => [
            // An operator registers a GLOBAL endpoint here, so a type nothing publishes
            // costs every tenant's events for it rather than one tenant's.
            'in' => 'Diesen Event-Typ veröffentlicht diese Anwendung nicht.',
        ],
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
        'subscriptions_table' => 'Deine Webhook-Endpunkte',
        'delivery_log_table' => 'Zustellprotokoll',
        'edit_subscription' => 'Endpunkt :url bearbeiten',
        'rotate_subscription' => 'Signaturschlüssel des Endpunkts :url rotieren',
        'delete_subscription' => 'Endpunkt :url löschen',
    ],
];
