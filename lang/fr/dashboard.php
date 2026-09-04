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
        'title' => "En un coup d'œil",
        'total' => 'Total des webhooks envoyés',
        'successful' => 'Réussis',
        'failed' => 'Échoués',
        'pending' => 'En attente',
        'retry_rate' => 'Taux de réessai',
    ],

    // Date patterns are translated, not just their month names: the ORDER differs by
    // locale (English leads with the month, French with the day).
    'formats' => [
        'hour_bucket' => 'j M H:i',
        'absolute' => 'LLL z',

        // 'precise' is a second pattern because 'absolute' cannot separate two rows and the
        // accessible names need it to. lang/en/dashboard.php states the case in full, including
        // what it still does not cover.
        'precise' => 'LL LTS z',
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
    // Shown when the rollup the counts come from has fallen behind the rows it summarizes --
    // twice the configured refresh cadence or more, so a run merely in progress never triggers it.
    'rollup_stale' => 'Les compteurs de livraison de cette page ont :minutes minutes de retard. Ils viennent d\'un rollup qu\'avance `webhooks:refresh-metrics` ; les latences à côté sont en direct, donc les deux divergent jusqu\'au prochain passage de cette commande.',

    // These agree with a noun that is not in the string, and four of the five used to get it wrong.
    // They label one column of one table, and the thing they label is a delivery (la livraison),
    // feminine in this language. Four were masculine and the fifth feminine, so the column read as
    // machine translation on the most-read screen in the package.
    //
    // No guard can check this: agreement is a fact about a word that never appears beside them. The
    // noun is written here so the next translator has it.
    'status' => [
        'pending' => 'en attente',
        'succeeded' => 'réussie',
        'failed' => 'échouée',
        'exhausted' => 'épuisée',
        'refused' => 'refusée',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'En attente',
        'succeeded' => 'Réussi',
        'failed' => 'Échoué',
        'exhausted' => 'Épuisé',
        'refused' => 'Refusée',
    ],

    'drawer' => [
        'close' => 'Fermer',
        'attempt' => 'Tentative :number',
        'http' => 'HTTP :code',
        'queued' => 'En file d\'attente',
        'delivered' => 'Livrée',
        'payload' => 'Payload',
        'replay' => 'Renvoyer la livraison',
        // Shown INSTEAD of the values when the payload ability denies the read. It has
        // to say why: a panel that just stops after its heading reads as a defect.
        'payload_redacted' => 'Les valeurs sont masquées. La structure reste visible pour que tu puisses vérifier la forme du corps sans lire les données qu\'il contient.',
        'payload_offloaded' => 'Ce corps était trop volumineux pour le journal et a été déplacé vers le disque :disk. Ce qui s\'affiche ici est le fragment conservé par le journal, pas le corps livré.',
        'payload_hidden' => 'Le corps est masqué. Tu n\'as pas l\'autorisation de consulter les payloads des livraisons.',
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
        'hour_summary' => [
            // Four choice fragments rather than one sentence with four numbers in it: agreement is
            // decided by the number, `trans_choice` takes one count per string, and there are four.
            // lang/en/dashboard.php states the case in full, including why English, German and
            // Dutch carry identical forms on both sides.
            'total' => '{0} :count au total|{1} :count au total|[2,*] :count au total',
            'delivered' => '{0} :count livrée|{1} :count livrée|[2,*] :count livrées',
            'pending' => '{0} :count en attente|{1} :count en attente|[2,*] :count en attente',
            'failed' => '{0} :count échouée|{1} :count échouée|[2,*] :count échouées',
        ],
        'latency_trend' => 'Tendance de la latence P95 par heure',
        'latency_bar' => ':hour : :value ms',
        'recent_deliveries_table' => 'Livraisons de webhooks récentes',
        'deliveries_table' => 'Livraisons de webhooks',
        'replay_delivery' => ':label — :event · :endpoint · :at',
        'view_delivery' => 'Voir les détails de la livraison :event vers :endpoint du :at',
        'delivery_details' => 'Détails de la livraison',
        'close_details' => 'Fermer les détails',
        'loading_kpis' => 'Chargement des indicateurs clés',
        'loading_chart' => 'Chargement du graphique d\'activité',
        'loading_panel' => 'Chargement du panneau',
        'loading_deliveries' => 'Chargement des livraisons',
    ],
];
