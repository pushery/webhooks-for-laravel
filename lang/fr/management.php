<?php

declare(strict_types=1);

// Copy for the publishable management stubs (the neutral views and their WireKit
// twins). Both variants render the same screens, so they read the same keys — a
// label fixed here stays fixed in both, and a host that restyles one variant keeps
// the wording of the other.
return [
    'form' => [
        'error_summary' => '{1} Le formulaire comporte une erreur.|[2,*] Le formulaire comporte :count erreurs.',
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
        // Le même formulaire, une fois qu'un endpoint est ouvert en modification.
        'submit_update' => 'Enregistrer les modifications',
        // Proposé uniquement en modification : un enregistrement est actif par définition.
        'active_label' => 'Actif',
    ],

    'secret' => [
        'heading' => 'Clé de signature (affichée une seule fois — enregistre-la maintenant)',
        // Une rotation dit quelque chose qu'un enregistrement ne dit pas, et il faut le dire
        // au lecteur : l'ancienne clé reste valide jusqu'à la fermeture de la fenêtre de
        // rotation. Voilà pourquoi une rotation en pleine incident peut se faire tout de suite.
        'rotated_heading' => 'Nouvelle clé de signature (affichée une seule fois — enregistre-la maintenant). L\'ancienne clé reste valide jusqu\'à la fermeture de la fenêtre de rotation.',
        // The one-time secret exists in a single response — this console has no reveal
        // window to ask again with — so the copy control is what stands between the reader
        // and a rotation nobody needed.
        'copy' => 'Copier',
        'copied' => 'Copié !',
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
        // Health bands, worded exactly as the self-service matrix words them: one
        // package, one vocabulary for the same state.
        'degraded' => 'Dégradé',
        'failing' => 'Défaillant',
        // Why an endpoint is off, not just that it is: the breaker and a person write
        // the same two columns, and only the failure streak separates them.
        'auto_disabled' => 'Désactivé automatiquement après :count échecs consécutifs',
        'enable' => 'Activer',
        'disable' => 'Désactiver',
        'edit' => 'Modifier',
        'rotate' => 'Faire tourner la clé',
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

    // Faire tourner la clé met l'ancienne sous délai plutôt que de l'invalider, mais cela
    // reste un changement que chaque récepteur doit suivre : les deux stubs le confirment
    // donc comme la suppression à côté.
    // Replaying sends a real HTTP request to a customer's endpoint, so it is never a bare
    // click -- the same reasoning as the rotate and delete confirmations below it.
    'redeliver_dialog' => [
        'title' => 'Renvoyer cette livraison ?',
        'description' => 'L\'événement est renvoyé sous son identifiant d\'origine. Un destinataire qui déduplique le traitera comme un événement déjà reçu.',
        'confirm' => 'Renvoyer',
    ],

    'rotate_dialog' => [
        'title' => 'Faire tourner cette clé de signature ?',
        'description' => 'Une nouvelle clé est émise immédiatement et affichée une seule fois. La clé actuelle reste valide jusqu\'à la fermeture de la fenêtre de rotation, mets donc le récepteur à jour avant.',
        'confirm' => 'Faire tourner la clé',
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
        // The unit, not a column header: the duration hangs off the response code rather
        // than standing in its own cell, because a duration without an answer says nothing.
        // Same string and same reasoning as the portal panel beside it.
        'duration' => ':ms ms',
    ],

    'filters' => [
        // The filter controls hide their labels visually, so these strings reach
        // sighted readers only through assistive technology — they are translated for
        // exactly the same reason a visible label is.
        'status' => 'Statut',
        'all_statuses' => 'Tous les statuts',
        'event_type' => 'Type d\'événement',
        'event_type_placeholder' => 'Filtrer par type d\'événement',
        'endpoint' => 'Endpoint',
        'all_endpoints' => 'Tous les endpoints',
        'all_event_types' => 'Tous les types d’événement',
        'from' => 'Du',
        'until' => 'Au',
        'endpoints_truncated' => 'Seuls les premiers endpoints sont proposés ici. Si celui que tu cherches manque, filtre par type d\'événement ou adapte ce stub.',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. Lowercase, as
    // in the original design.
    'status' => [
        'pending' => 'en attente',
        'succeeded' => 'réussi',
        'failed' => 'échoué',
        'exhausted' => 'épuisé',
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

    'messages' => [
        // Shown when a replay is asked for an endpoint that is switched off — by its
        // tenant, or by the circuit breaker after too many failures.
        'endpoint_disabled' => 'Cet endpoint est désactivé. Réactive-le avant de lui renvoyer une livraison.',

        // Affiché quand un ping de test est refusé : le quota de la minute est épuisé.
        'ping_throttled' => 'Cet endpoint a épuisé ses pings de test pour le moment. Réessaie dans :seconds seconde(s).',
    ],

    'validation' => [
        'event_types' => [
            'string' => 'Un type d\'événement doit être un nom, pas un nombre ni une liste.',
            // An operator registers a GLOBAL endpoint here, so a type nothing publishes
            // costs every tenant's events for it rather than one tenant's.
            'in' => 'Ce type d\'événement n\'est pas publié par cette application.',
        ],
        'url' => [
            // The scheme narrowing on the rule ('url:http,https') is what produces this,
            // and without a line here the operator console fell back to the framework's
            // own English 'must be a valid URL' -- for a form whose every other message
            // is translated.
            'url' => 'Saisis une URL d\'endpoint valide. Elle doit commencer par http:// ou https://.',
            // What the reader gets when the SSRF guard refuses the destination. The
            // guard's own message stays untranslated: it is an operator diagnostic for
            // the log, and it would tell a stranger which hosts resolve where.
            'blocked' => 'Cette URL ne peut pas être utilisée comme endpoint. Utilise une URL https accessible publiquement.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface.
    'a11y' => [
        'subscriptions_table' => 'Tes endpoints de webhook',
        'delivery_log_table' => 'Journal des livraisons',
        'edit_subscription' => 'Modifier l\'endpoint :url',
        'rotate_subscription' => ':label — :url',
        'redeliver_delivery' => ':label — :event · :endpoint · :at',
        'toggle_subscription' => ':label — :url',
        'ping_subscription' => ':label — :url',
        'delete_subscription' => 'Supprimer l\'endpoint :url',
    ],
];
