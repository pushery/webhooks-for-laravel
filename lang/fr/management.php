<?php

declare(strict_types=1);

// Copy for the publishable management stubs (the neutral views and their WireKit
// twins). Both variants render the same screens, so they read the same keys — a
// label fixed here stays fixed in both, and a host that restyles one variant keeps
// the wording of the other.
return [
    'form' => [
        'name_label' => 'Nom',
        'url_label' => 'URL de l\'endpoint',
        // An example URL, not prose, but it reaches the reader as a placeholder, so a
        // locale can point it at a domain its audience recognizes.
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_legend' => 'Types d\'événements',
        // The file path travels as a placeholder so a locale is free to put it wherever
        // its grammar wants it.
        'event_types_empty' => 'Configure les types d\'événements dans :file.',
        'submit' => 'Enregistrer l\'endpoint',
    ],

    'secret' => [
        'heading' => 'Clé de signature (affichée une seule fois — enregistre-la maintenant)',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'events' => 'Événements',
        'status' => 'Statut',
        'event' => 'Événement',
        'attempt' => 'Tentative',
        'code' => 'Code',
        'when' => 'Quand',
        // The actions column shows no visible header, but a column still needs an
        // accessible name — it is read out, so it is translated.
        'actions' => 'Actions',
    ],

    'subscription' => [
        'active' => 'Actif',
        'disabled' => 'Désactivé',
        'enable' => 'Activer',
        'disable' => 'Désactiver',
        'delete' => 'Supprimer',
    ],

    // Deleting an endpoint is irreversible and stops a live integration, so both stubs
    // confirm it first — the WireKit variant through an alert-dialog, the neutral one
    // through the browser confirm.
    'delete_dialog' => [
        'title' => 'Supprimer cet endpoint ?',
        'description' => 'L\'endpoint cesse immédiatement de recevoir des webhooks et sa clé de signature est détruite. Cette action est irréversible.',
        'confirm' => 'Supprimer l\'endpoint',
    ],

    'actions' => [
        'cancel' => 'Annuler',
    ],

    'empty' => [
        'no_subscriptions' => [
            'title' => 'Aucun endpoint pour le moment',
            'description' => 'Enregistre ton premier endpoint ci-dessus pour commencer à livrer des webhooks.',
        ],
        'no_deliveries' => [
            'title' => 'Aucune livraison trouvée',
            'description' => 'Les livraisons apparaissent ici au fur et à mesure de l\'envoi de tes événements. Retire un filtre pour en voir davantage.',
        ],
    ],

    'deliveries' => [
        'redeliver' => 'Renvoyer',
        'ping' => 'Tester',
    ],

    'filters' => [
        // The filter controls hide their labels visually, so these strings reach
        // sighted readers only through assistive technology — they are translated for
        // exactly the same reason a visible label is.
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

    'messages' => [
        // Shown when a replay is asked for an endpoint that is switched off — by its
        // tenant, or by the circuit breaker after too many failures.
        'endpoint_disabled' => 'Cet endpoint est désactivé. Réactive-le avant de lui renvoyer une livraison.',
    ],

    'validation' => [
        'url' => [
            // What the reader gets when the SSRF guard refuses the destination. The
            // guard's own message stays untranslated: it is an operator diagnostic for
            // the log, and it would tell a stranger which hosts resolve where.
            'blocked' => 'Cette URL ne peut pas être utilisée comme endpoint. Utilise une URL https accessible publiquement.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface.
    'a11y' => [
        'delete_subscription' => 'Supprimer l\'endpoint :url',
    ],
];
