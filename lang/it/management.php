<?php

declare(strict_types=1);

// Copy for the publishable management stubs (the neutral views and their WireKit
// twins). Both variants render the same screens, so they read the same keys — a
// label fixed here stays fixed in both, and a host that restyles one variant keeps
// the wording of the other.
return [
    'form' => [
        'error_summary' => '{1} Il modulo contiene un errore.|[2,*] Il modulo contiene :count errori.',
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
        // The one-time secret exists in a single response — this console has no reveal
        // window to ask again with — so the copy control is what stands between the reader
        // and a rotation nobody needed.
        'copy' => 'Copia',
        'copied' => 'Copiato!',
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
        // Health bands, worded exactly as the self-service matrix words them: one
        // package, one vocabulary for the same state.
        'degraded' => 'Degradato',
        'failing' => 'In errore',
        // Why an endpoint is off, not just that it is: the breaker and a person write
        // the same two columns, and only the failure streak separates them.
        'auto_disabled' => 'Disattivato automaticamente dopo :count errori consecutivi',
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
    // Replaying sends a real HTTP request to a customer's endpoint, so it is never a bare
    // click -- the same reasoning as the rotate and delete confirmations below it.
    'redeliver_dialog' => [
        'title' => 'Inviare di nuovo questa consegna?',
        'description' => 'L\'evento viene inviato di nuovo con il suo identificatore originale. Un destinatario che effettua la deduplica lo tratterà come uno già visto.',
        'confirm' => 'Invia di nuovo',
    ],

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
        // The unit, not a column header: the duration hangs off the response code rather
        // than standing in its own cell, because a duration without an answer says nothing.
        // Same string and same reasoning as the portal panel beside it.
        'duration' => ':ms ms',
    ],

    'filters' => [
        // The filter controls hide their labels visually, so these strings reach
        // sighted readers only through assistive technology — they are translated for
        // exactly the same reason a visible label is.
        'status' => 'Stato',
        'all_statuses' => 'Tutti gli stati',
        'event_type' => 'Tipo di evento',
        'event_type_placeholder' => 'Filtra per tipo di evento',
        'endpoint' => 'Endpoint',
        'all_endpoints' => 'Tutti gli endpoint',
        'all_event_types' => 'Tutti i tipi di evento',
        'from' => 'Dal',
        'until' => 'Al',
        'endpoints_truncated' => 'Qui vengono offerti solo i primi endpoint. Se manca quello che cerchi, filtra per tipo di evento oppure adatta questo stub.',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. Lowercase, as
    // in the original design.
    'status' => [
        'pending' => 'in attesa',
        'succeeded' => 'riuscita',
        'failed' => 'fallita',
        'exhausted' => 'esaurita',
        'refused' => 'rifiutata',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'In attesa',
        'succeeded' => 'Riuscita',
        'failed' => 'Fallita',
        'exhausted' => 'Esaurita',
        'refused' => 'Rifiutata',
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
            'string' => 'Un tipo di evento deve essere un nome, non un numero né un elenco.',
            // An operator registers a GLOBAL endpoint here, so a type nothing publishes
            // costs every tenant's events for it rather than one tenant's.
            'in' => 'Questo tipo di evento non è pubblicato da questa applicazione.',
        ],
        'url' => [
            // The scheme narrowing on the rule ('url:http,https') is what produces this,
            // and without a line here the operator console fell back to the framework's
            // own English 'must be a valid URL' -- for a form whose every other message
            // is translated.
            'url' => 'Inserisci un URL endpoint valido. Deve iniziare con http:// o https://.',
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
        'rotate_subscription' => ':label — :url',
        'redeliver_delivery' => ':label — :event · :endpoint · :at',
        'toggle_subscription' => ':label — :url',
        'ping_subscription' => ':label — :url',
        'delete_subscription' => 'Elimina endpoint :url',
    ],
];
