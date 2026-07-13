<?php

declare(strict_types=1);

return [
    // Der Fenstertitel jeder Dashboard-Seite (gemeinsames Layout).
    'title' => 'Webhooks',

    'heading' => 'Webhooks',

    // Die Registerkarte selbst wird über den unveränderten Schlüssel adressiert;
    // übersetzt wird nur die Beschriftung — und zwar fertig geschrieben, nicht per CSS
    // umgeformt.
    'tabs' => [
        'overview' => 'Übersicht',
        'webhooks' => 'Webhooks',
        'queue' => 'Warteschlange',
        'documentation' => 'Dokumentation',
    ],

    'kpis' => [
        'total' => 'Gesendete Webhooks gesamt',
        'successful' => 'Erfolgreich',
        'failed' => 'Fehlgeschlagen',
        'pending' => 'Ausstehend',
        'retry_rate' => 'Wiederholungsrate',
    ],

    // Datumsmuster werden mitübersetzt, nicht nur die Monatsnamen: die REIHENFOLGE
    // unterscheidet sich je Sprache (Englisch führt mit dem Monat, Deutsch mit dem Tag).
    'formats' => [
        'hour_bucket' => 'j. M H:00',
    ],

    'api' => [
        'unsupported_window' => 'Das angefragte Kennzahlen-Fenster wird nicht unterstützt. Unterstützte Fenster: :windows.',
        'invalid_window' => 'Das gewählte Fenster ist ungültig. Unterstützte Fenster: :windows.',
    ],

    'activity' => [
        'title' => 'Stündliche Aktivität',
        'delivered' => 'Zugestellt',
        'pending' => 'Ausstehend',
        'failed' => 'Fehlgeschlagen',
        'bar_title' => ':hour — :total gesamt',
    ],

    'latency' => [
        'title' => 'Latenz (ms)',
        'p95_trend' => 'P95-Verlauf',
    ],

    'top_events' => [
        'title' => 'Häufigste Events',
    ],

    'recent' => [
        'title' => 'Aktuelle Warteschlange',
    ],

    'setup' => [
        'title' => 'Endpunkte',
        'total' => 'Gesamt',
        'active' => 'Aktiv',
        'disabled' => 'Deaktiviert',
    ],

    'table' => [
        'event' => 'Event',
        'status' => 'Status',
        'attempt' => 'Versuch',
        'code' => 'Code',
        'duration' => 'Dauer',
        'when' => 'Zeitpunkt',
        'actions' => 'Aktionen',
        'replay' => 'Erneut senden',
    ],

    'filters' => [
        'status' => 'Status',
        'all_statuses' => 'Alle Status',
        'event_type' => 'Event-Typ',
        'event_type_placeholder' => 'Nach Event-Typ filtern',
    ],

    // Der Schlüssel ist der gespeicherte Statuswert und bleibt unverändert;
    // übersetzt wird nur die Beschriftung. Kleinschreibung wie im Original-Design.
    'status' => [
        'pending' => 'ausstehend',
        'succeeded' => 'erfolgreich',
        'failed' => 'fehlgeschlagen',
        'exhausted' => 'aufgegeben',
    ],

    'status_options' => [
        'pending' => 'Ausstehend',
        'succeeded' => 'Erfolgreich',
        'failed' => 'Fehlgeschlagen',
        'exhausted' => 'Aufgegeben',
    ],

    'drawer' => [
        'close' => 'Schließen',
        'attempt' => 'Versuch :number',
        'http' => 'HTTP :code',
        'queued' => 'In Warteschlange',
        'delivered' => 'Zugestellt',
        'payload' => 'Payload',
        'replay' => 'Zustellung erneut senden',
    ],

    'empty' => [
        'no_activity' => [
            'title' => 'Noch keine Aktivität',
            'description' => 'Zustellungen in diesem Zeitraum erscheinen hier als stündliche Aufschlüsselung.',
        ],
        'no_events' => [
            'title' => 'Noch keine Events',
            'description' => 'Hier siehst du deine häufigsten Event-Typen nach Anzahl.',
        ],
        'no_deliveries' => [
            'title' => 'Noch keine Zustellungen',
            'description' => 'Sobald deine Events gesendet werden, erscheinen die Zustellungen hier.',
        ],
        'no_deliveries_found' => [
            'title' => 'Keine Zustellungen gefunden',
            'description' => 'Keine Zustellung passt zu den aktuellen Filtern. Entferne einen Filter, um mehr zu sehen.',
        ],
        'no_endpoints' => [
            'title' => 'Keine Endpunkte registriert',
            'description' => 'Registriere einen Webhook-Endpunkt, um Zustellungen zu empfangen.',
        ],
    ],

    'docs' => [
        'title' => 'Dokumentation',
        'body' => 'Registriere Endpunkte, signiere jede Zustellung mit dem Standard-Webhooks-Verfahren und sende jede Zustellung aus diesem Dashboard erneut. Die vollständige Konfigurationsreferenz und den Event-Katalog findest du in der README des Pakets.',
    ],

    'toast' => [
        'redelivery_queued' => 'Erneute Zustellung eingereiht.',
        'endpoint_disabled' => 'Dieser Endpunkt ist deaktiviert. Aktiviere ihn wieder, bevor du eine Zustellung erneut sendest.',
    ],

    // Beschriftungen, die nur eine Vorlesesoftware ankündigt. Ein nicht übersetzter
    // barrierefreier Name ist eine nicht übersetzte Oberfläche.
    'a11y' => [
        'skip_to_content' => 'Direkt zum Dashboard-Inhalt',
        'time_window' => 'Zeitraum',
        'sections' => 'Dashboard-Bereiche',
        'retry_rate' => 'Wiederholungsrate',
        'deliveries_per_hour' => 'Zustellungen pro Stunde',
        'hour_summary' => ':hour: :total gesamt, :delivered zugestellt, :pending ausstehend, :failed fehlgeschlagen',
        'latency_trend' => 'P95-Latenzverlauf pro Stunde',
        'recent_deliveries_table' => 'Aktuelle Webhook-Zustellungen',
        'deliveries_table' => 'Webhook-Zustellungen',
        'replay_delivery' => 'Zustellung :event erneut senden',
        'view_delivery' => 'Details der Zustellung :event ansehen',
        'delivery_details' => 'Zustellungsdetails',
        'close_details' => 'Details schließen',
        'loading_kpis' => 'Kennzahlen werden geladen',
        'loading_chart' => 'Aktivitätsdiagramm wird geladen',
        'loading_panel' => 'Bereich wird geladen',
        'loading_deliveries' => 'Zustellungen werden geladen',
    ],
];
