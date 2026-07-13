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
        'overview' => 'Aperçu',
        'webhooks' => 'Webhooks',
        'queue' => 'File d\'attente',
        'documentation' => 'Documentation',
    ],

    'kpis' => [
        'total' => 'Total des webhooks envoyés',
        'successful' => 'Réussis',
        'failed' => 'Échoués',
        'pending' => 'En attente',
        'retry_rate' => 'Taux de réessai',
    ],

    // Date patterns are translated, not just their month names: the ORDER differs by
    // locale (English leads with the month, French with the day).
    'formats' => [
        'hour_bucket' => 'j M H:00',
    ],

    'api' => [
        'unsupported_window' => 'La fenêtre de métriques demandée n\'est pas prise en charge. Fenêtres prises en charge : :windows.',
        'invalid_window' => 'La fenêtre sélectionnée n\'est pas valide. Fenêtres prises en charge : :windows.',
    ],

    'activity' => [
        'title' => 'Activité horaire',
        'delivered' => 'Livrées',
        'pending' => 'En attente',
        'failed' => 'Échouées',
        'bar_title' => ':hour — :total au total',
    ],

    'latency' => [
        'title' => 'Latence (ms)',
        'p95_trend' => 'Tendance P95',
    ],

    'top_events' => [
        'title' => 'Événements les plus fréquents',
    ],

    'recent' => [
        'title' => 'File d\'attente récente',
    ],

    'setup' => [
        'title' => 'Endpoints',
        'total' => 'Total',
        'active' => 'Actifs',
        'disabled' => 'Désactivés',
    ],

    'table' => [
        'event' => 'Événement',
        'status' => 'Statut',
        'attempt' => 'Tentative',
        'code' => 'Code',
        'duration' => 'Durée',
        'when' => 'Quand',
        'actions' => 'Actions',
        'replay' => 'Renvoyer',
    ],

    'filters' => [
        'status' => 'Statut',
        'all_statuses' => 'Tous les statuts',
        'event_type' => 'Type d\'événement',
        'event_type_placeholder' => 'Filtrer par type d\'événement',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. Lowercase, as
    // in the original design.
    'status' => [
        'pending' => 'en attente',
        'succeeded' => 'réussi',
        'failed' => 'échoué',
        'exhausted' => 'épuisé',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'En attente',
        'succeeded' => 'Réussi',
        'failed' => 'Échoué',
        'exhausted' => 'Épuisé',
    ],

    'drawer' => [
        'close' => 'Fermer',
        'attempt' => 'Tentative :number',
        'http' => 'HTTP :code',
        'queued' => 'En file d\'attente',
        'delivered' => 'Livrée',
        'payload' => 'Payload',
        'replay' => 'Renvoyer la livraison',
    ],

    'empty' => [
        'no_activity' => [
            'title' => 'Aucune activité pour le moment',
            'description' => 'Les livraisons de cette période apparaîtront ici, ventilées par heure.',
        ],
        'no_events' => [
            'title' => 'Aucun événement pour le moment',
            'description' => 'Tes types d\'événements les plus fréquents seront classés ici.',
        ],
        'no_deliveries' => [
            'title' => 'Aucune livraison pour le moment',
            'description' => 'Les livraisons s\'afficheront ici au fur et à mesure de l\'envoi de tes événements.',
        ],
        'no_deliveries_found' => [
            'title' => 'Aucune livraison trouvée',
            'description' => 'Aucune livraison ne correspond aux filtres actuels. Retire un filtre pour en voir davantage.',
        ],
        'no_endpoints' => [
            'title' => 'Aucun endpoint enregistré',
            'description' => 'Enregistre un endpoint de webhook pour commencer à recevoir des livraisons.',
        ],
    ],

    'docs' => [
        'title' => 'Documentation',
        'body' => 'Enregistre des endpoints, signe chaque livraison avec le schéma Standard Webhooks et renvoie n\'importe quelle livraison depuis ce tableau de bord. Consulte le README du paquet pour la référence de configuration complète et le catalogue des événements.',
    ],

    'toast' => [
        'redelivery_queued' => 'Renvoi ajouté à la file d\'attente.',
        'endpoint_disabled' => 'Cet endpoint est désactivé. Réactive-le avant de lui renvoyer une livraison.',
    ],

    // Strings a reader never sees but a screen reader always announces. An
    // untranslated accessible name is an untranslated interface, so they live here
    // with the visible copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Aller directement au contenu du tableau de bord',
        'time_window' => 'Période',
        'sections' => 'Sections du tableau de bord',
        'retry_rate' => 'Taux de réessai',
        'deliveries_per_hour' => 'Livraisons par heure',
        'hour_summary' => ':hour : :total au total, :delivered livrées, :pending en attente, :failed échouées',
        'latency_trend' => 'Tendance de la latence P95 par heure',
        'recent_deliveries_table' => 'Livraisons de webhooks récentes',
        'deliveries_table' => 'Livraisons de webhooks',
        'replay_delivery' => 'Renvoyer la livraison :event',
        'view_delivery' => 'Voir les détails de la livraison :event',
        'delivery_details' => 'Détails de la livraison',
        'close_details' => 'Fermer les détails',
        'loading_kpis' => 'Chargement des indicateurs clés',
        'loading_chart' => 'Chargement du graphique d\'activité',
        'loading_panel' => 'Chargement du panneau',
        'loading_deliveries' => 'Chargement des livraisons',
    ],
];
