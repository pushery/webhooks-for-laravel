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
        // Lo stesso modulo, quando un endpoint è aperto in modifica.
        'submit_update' => 'Salva le modifiche',
        // Offerto solo in modifica: una registrazione è attiva per definizione.
        'active_label' => 'Attivo',
    ],

    'secret' => [
        'heading' => 'Chiave di firma (mostrata una sola volta — salvala ora)',
        // Una rotazione dice qualcosa che una registrazione non dice, e chi legge deve saperlo:
        // la chiave precedente resta valida finché la finestra di rotazione non si chiude. È
        // per questo che durante un incidente si può ruotare subito.
        'rotated_heading' => 'Nuova chiave di firma (mostrata una sola volta — salvala ora). La chiave precedente resta valida finché la finestra di rotazione non si chiude.',
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
        'edit' => 'Modifica',
        'rotate' => 'Ruota chiave',
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

    // Ruotare mette la chiave precedente sotto scadenza invece di invalidarla, ma resta
    // un cambiamento che ogni ricevente deve seguire: entrambi gli stub lo confermano
    // come l'eliminazione accanto.
    'rotate_dialog' => [
        'title' => 'Ruotare questa chiave di firma?',
        'description' => 'Viene emessa subito una nuova chiave, mostrata una sola volta. La chiave attuale resta valida finché la finestra di rotazione non si chiude: aggiorna il ricevente prima di allora.',
        'confirm' => 'Ruota chiave',
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

        // Mostrato quando un ping di prova viene rifiutato: il contingente del minuto è esaurito.
        'ping_throttled' => 'Questo endpoint ha esaurito i suoi ping di prova per ora. Riprova tra :seconds secondo/i.',
    ],

    'validation' => [
        'event_types' => [
            // An operator registers a GLOBAL endpoint here, so a type nothing publishes
            // costs every tenant's events for it rather than one tenant's.
            'in' => 'Questo tipo di evento non è pubblicato da questa applicazione.',
        ],
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
        'subscriptions_table' => 'I tuoi endpoint webhook',
        'delivery_log_table' => 'Registro delle consegne',
        'edit_subscription' => 'Modifica endpoint :url',
        'rotate_subscription' => 'Ruota la chiave di firma dell\'endpoint :url',
        'delete_subscription' => 'Elimina endpoint :url',
    ],
];
