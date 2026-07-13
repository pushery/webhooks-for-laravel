<?php

declare(strict_types=1);

return [
    // The browser window title for every self-service page (the shared layout).
    'title' => 'Endpoints de webhook',

    'page' => [
        'heading' => 'Endpoints de webhook',
        'intro' => 'Enregistre les endpoints sur lesquels ton application doit recevoir les webhooks, choisis les événements que chacun écoute et gère sa clé de signature.',
        'health_link' => 'État des endpoints',
    ],

    'list' => [
        'heading' => 'Tes endpoints',
        'new_endpoint' => 'Nouvel endpoint',
        'cap_reached' => 'Limite d\'endpoints atteinte.',
        'secret' => 'Clé',
        'edit' => 'Modifier',
        'transform' => 'Transformer',
        'delete' => 'Supprimer',
        'active' => 'Actif',
        'disabled' => 'Désactivé',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'health' => 'État',
        'events' => 'Événements',
        'status' => 'Statut',
        'score' => 'Score',
        'success_rate' => 'Taux de réussite',
        'p95' => 'p95',
        'sample' => 'Échantillon',
        'as_of' => 'Au',
        'actions' => 'Actions',
    ],

    // Badge labels for the stored health band. The key is the persisted health_status
    // value and is never translated; only the label a reader sees is.
    'health' => [
        'healthy' => 'Sain',
        'degraded' => 'Dégradé',
        'failing' => 'Défaillant',
        'unknown' => 'Inconnu',
    ],

    'form' => [
        'new_heading' => 'Nouvel endpoint',
        'edit_heading' => 'Modifier l\'endpoint',
        'name_label' => 'Nom',
        'name_hint' => 'Un libellé facultatif pour reconnaître cet endpoint.',
        'url_label' => 'URL de l\'endpoint',
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_label' => 'Types d\'événements',
        'no_event_types' => 'Aucun type d\'événement n\'est encore configuré pour cette application.',
        'active_label' => 'Actif',
        'active_hint' => 'Les livraisons ne sont envoyées que tant qu\'un endpoint est actif.',
        'register' => 'Enregistrer l\'endpoint',
        'save' => 'Enregistrer les modifications',
    ],

    'delete_dialog' => [
        'title' => 'Supprimer cet endpoint ?',
        'description' => 'Cela supprime définitivement l\'endpoint et arrête toutes les livraisons vers celui-ci. Cette action est irréversible.',
        'confirm' => 'Supprimer l\'endpoint',
    ],

    'secret' => [
        'heading' => 'Clé de signature',
        'hide' => 'Masquer',
        'hidden_announcement' => 'Clé de signature masquée.',
        'notice' => 'Enregistre cette clé maintenant — elle n\'est affichée que brièvement et ne pourra pas être récupérée plus tard. Vérifie la signature de chaque livraison avec elle.',
        // The whole sentence is one translatable unit so a locale can put the number
        // where its grammar wants it; the countdown re-renders it from this same string
        // on every tick.
        'countdown' => 'Cette clé se masque automatiquement dans :seconds s.',
        'countdown_warning' => 'La clé de signature se masque dans 10 secondes.',
        'copy' => 'Copier',
        'copied' => 'Copié !',
        'previous' => 'Clé précédente (encore acceptée pendant le renouvellement)',
        'rotate' => 'Renouveler la clé',
    ],

    'health_page' => [
        'heading' => 'État des endpoints',
        'intro' => 'L\'état de chacun de tes endpoints, évalué à partir de son historique de livraisons récent. Recalcule pour actualiser un score et voir son dernier taux de réussite, sa latence et sa taille d\'échantillon.',
        'recompute' => 'Recalculer',
        'recompute_all' => 'Tout recalculer',
        'never' => 'Jamais',
    ],

    'transform' => [
        'heading' => 'Transformation du payload',
        'versioning_disabled' => 'Le versionnage du payload est actuellement désactivé. Tu peux tout de même modifier et enregistrer cette transformation ; elle ne remodèlera pas les livraisons tant que le versionnage n\'est pas activé.',
        'rules' => 'Règles',
        'version_label' => 'Version du payload',
        'version_hint' => 'Inscrite dans le corps sous payload_version pour qu\'un destinataire reconnaisse la forme des données envoyées.',
        'version_none' => 'Aucune',
        'field_name_placeholder' => 'nom du champ',
        'include_label' => 'Inclure des champs',
        'include_hint' => 'Seuls ces champs sont conservés. Laisse vide pour tous les garder.',
        'add_include' => 'Ajouter un champ à inclure',
        'exclude_label' => 'Exclure des champs',
        'exclude_hint' => 'Ces champs sont retirés du corps.',
        'add_exclude' => 'Ajouter un champ à exclure',
        'rename_label' => 'Renommer des champs',
        'rename_hint' => 'Déplacer un champ vers un nouveau nom.',
        'rename_from_placeholder' => 'de',
        'rename_to_placeholder' => 'vers',
        'add_rename' => 'Ajouter un renommage',
        'rewrap_label' => 'Clé d\'encapsulation',
        'rewrap_hint' => 'Imbriquer tout le corps sous une seule clé. Laisse vide pour l\'envoyer sans encapsulation.',
        'rewrap_placeholder' => 'data',
        'save' => 'Enregistrer la transformation',
        'preview_heading' => 'Aperçu en direct',
        'sample_label' => 'Exemple de payload',
        'sample_hint' => 'Modifie ceci pour prévisualiser avec tes propres données.',
        'invalid_json' => 'Ce n\'est pas un objet JSON lisible, il n\'y a donc rien à prévisualiser. Vérifie s\'il y a une virgule en trop ou un guillemet manquant.',
        'input' => 'Entrée',
        'output' => 'Sortie',
    ],

    'empty' => [
        'no_endpoints' => [
            'title' => 'Aucun endpoint pour le moment',
            'description' => 'Enregistre ton premier endpoint de webhook pour commencer à recevoir des événements.',
        ],
        'no_endpoints_health' => [
            'title' => 'Aucun endpoint pour le moment',
            'description' => 'Enregistre un endpoint de webhook pour commencer à suivre son état ici.',
        ],
    ],

    'actions' => [
        'cancel' => 'Annuler',
        'remove' => 'Retirer',
        'back_to_endpoints' => 'Retour aux endpoints',
    ],

    // The cap is announced both as a warning toast and as an error on the URL field, so
    // the tenant reads the same sentence wherever it is refused.
    'limit_reached' => 'Tu as atteint ta limite d\'endpoints.',

    'toast' => [
        'endpoint_registered' => 'Endpoint enregistré.',
        'endpoint_updated' => 'Endpoint mis à jour.',
        'endpoint_deleted' => 'Endpoint supprimé.',
        'secret_rotated' => 'Clé de signature renouvelée.',
        'health_recomputed' => 'État de l\'endpoint recalculé.',
        'health_recomputed_all' => 'État recalculé pour tous les endpoints.',
        'transform_saved' => 'Transformation du payload enregistrée.',
    ],

    // The form's own validation copy, passed to the validator as custom messages and
    // attribute names, so a refused save speaks the reader's language rather than the
    // framework's default English lines.
    'validation' => [
        'name' => [
            'max' => 'Le nom ne peut pas dépasser :max caractères.',
        ],
        'url' => [
            'required' => 'Une URL d\'endpoint est requise.',
            'url' => 'Saisis une URL d\'endpoint valide.',
            // The SSRF guard's own message names the resolved host and address, which is
            // a probe oracle in a tenant-facing form. The reason the URL was refused is
            // always the same one a tenant can act on: it must be public and https.
            'blocked' => 'Cette URL ne peut pas être utilisée comme endpoint. Utilise une URL https accessible publiquement.',
        ],
        'event_types' => [
            'required' => 'Sélectionne au moins un type d\'événement.',
            'min' => 'Sélectionne au moins un type d\'événement.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface, so they live here with the visible
    // copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Aller directement au contenu de la page',
        'loading_endpoints' => 'Chargement des endpoints',
        'endpoints_table' => 'Tes endpoints de webhook',
        'health_table' => 'État des endpoints',
        'toggle_active' => 'Activer ou désactiver l\'état actif pour :url',
        'reveal_secret' => 'Afficher la clé de signature pour :url',
        'edit_endpoint' => 'Modifier l\'endpoint :url',
        'edit_transform' => 'Modifier la transformation du payload pour :url',
        'delete_endpoint' => 'Supprimer l\'endpoint :url',
        'recompute_health' => 'Recalculer l\'état pour :url',
        'include_field' => 'Champ à inclure :number',
        'remove_include_field' => 'Retirer le champ à inclure :number',
        'exclude_field' => 'Champ à exclure :number',
        'remove_exclude_field' => 'Retirer le champ à exclure :number',
        'rename_source_field' => 'Champ source du renommage :number',
        'rename_target_field' => 'Champ cible du renommage :number',
        'remove_rename_pair' => 'Retirer la paire de renommage :number',
        'output_preview' => 'Aperçu de la sortie transformée',
        // A short, stable announcement beside the output pane. The pane itself is not a
        // live region: it is recomputed on every debounced keystroke, and announcing the
        // whole JSON body every 400 ms would make the editor unusable with a screen reader.
        'output_updated' => 'Aperçu mis à jour.',
    ],
];
