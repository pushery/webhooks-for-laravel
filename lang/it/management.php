<?php

declare(strict_types=1);

// Copy for the publishable management stubs (the neutral views and their WireKit
// twins). Both variants render the same screens, so they read the same keys — a
// label fixed here stays fixed in both, and a host that restyles one variant keeps
// the wording of the other.
return [
    'form' => [
        'name_label' => 'Nome',
        'url_label' => 'URL dell\'endpoint',
        // An example URL, not prose, but it reaches the reader as a placeholder, so a
        // locale can point it at a domain its audience recognizes.
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_legend' => 'Tipi di evento',
        // The file path travels as a placeholder so a locale is free to put it wherever
        // its grammar wants it.
        'event_types_empty' => 'Configura i tipi di evento in :file.',
        'submit' => 'Registra endpoint',
    ],

    'secret' => [
        'heading' => 'Chiave di firma (mostrata una sola volta — salvala ora)',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'events' => 'Eventi',
        'status' => 'Stato',
        'event' => 'Evento',
        'attempt' => 'Tentativo',
        'code' => 'Codice',
        'when' => 'Quando',
        // The actions column shows no visible header, but a column still needs an
        // accessible name — it is read out, so it is translated.
        'actions' => 'Azioni',
    ],

    'subscription' => [
        'active' => 'Attivo',
        'disabled' => 'Disattivato',
        'enable' => 'Attiva',
        'disable' => 'Disattiva',
        'delete' => 'Elimina',
    ],

    // Deleting an endpoint is irreversible and stops a live integration, so both stubs
    // confirm it first — the WireKit variant through an alert-dialog, the neutral one
    // through the browser confirm.
    'delete_dialog' => [
        'title' => 'Eliminare questo endpoint?',
        'description' => 'L\'endpoint smette immediatamente di ricevere webhook e la sua chiave di firma viene distrutta. Questa operazione non può essere annullata.',
        'confirm' => 'Elimina endpoint',
    ],

    'actions' => [
        'cancel' => 'Annulla',
    ],

    'empty' => [
        'no_subscriptions' => [
            'title' => 'Ancora nessun endpoint',
            'description' => 'Registra qui sopra il tuo primo endpoint per iniziare a inviare webhook.',
        ],
        'no_deliveries' => [
            'title' => 'Nessuna consegna trovata',
            'description' => 'Le consegne compaiono qui man mano che i tuoi eventi vengono inviati. Rimuovi un filtro per vederne altre.',
        ],
    ],

    'deliveries' => [
        'redeliver' => 'Reinvia',
        'ping' => 'Invia test',
    ],

    'filters' => [
        // The filter controls hide their labels visually, so these strings reach
        // sighted readers only through assistive technology — they are translated for
        // exactly the same reason a visible label is.
        'status' => 'Stato',
        'all_statuses' => 'Tutti gli stati',
        'event_type' => 'Tipo di evento',
        'event_type_placeholder' => 'Filtra per tipo di evento',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. Lowercase, as
    // in the original design.
    'status' => [
        'pending' => 'in attesa',
        'succeeded' => 'riuscita',
        'failed' => 'fallita',
        'exhausted' => 'esaurita',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'In attesa',
        'succeeded' => 'Riuscita',
        'failed' => 'Fallita',
        'exhausted' => 'Esaurita',
    ],

    'messages' => [
        // Shown when a replay is asked for an endpoint that is switched off — by its
        // tenant, or by the circuit breaker after too many failures.
        'endpoint_disabled' => 'Questo endpoint è disattivato. Riattivalo prima di reinviargli una consegna.',
    ],

    'validation' => [
        'url' => [
            // What the reader gets when the SSRF guard refuses the destination. The
            // guard's own message stays untranslated: it is an operator diagnostic for
            // the log, and it would tell a stranger which hosts resolve where.
            'blocked' => 'Questo URL non può essere usato come endpoint. Usa un URL https raggiungibile pubblicamente.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface.
    'a11y' => [
        'delete_subscription' => 'Elimina endpoint :url',
    ],
];
