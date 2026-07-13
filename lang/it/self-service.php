<?php

declare(strict_types=1);

return [
    // The browser window title for every self-service page (the shared layout).
    'title' => 'Endpoint webhook',

    'page' => [
        'heading' => 'Endpoint webhook',
        'intro' => 'Registra gli endpoint su cui la tua applicazione deve ricevere i webhook, scegli gli eventi su cui ciascuno è in ascolto e gestisci la relativa chiave di firma.',
        'health_link' => 'Salute degli endpoint',
    ],

    'list' => [
        'heading' => 'I tuoi endpoint',
        'new_endpoint' => 'Nuovo endpoint',
        'cap_reached' => 'Limite di endpoint raggiunto.',
        'secret' => 'Chiave',
        'edit' => 'Modifica',
        'transform' => 'Trasforma',
        'delete' => 'Elimina',
        'active' => 'Attivo',
        'disabled' => 'Disattivato',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'health' => 'Salute',
        'events' => 'Eventi',
        'status' => 'Stato',
        'score' => 'Punteggio',
        'success_rate' => 'Tasso di successo',
        'p95' => 'p95',
        'sample' => 'Campione',
        'as_of' => 'Aggiornato al',
        'actions' => 'Azioni',
    ],

    // Badge labels for the stored health band. The key is the persisted health_status
    // value and is never translated; only the label a reader sees is.
    'health' => [
        'healthy' => 'Sano',
        'degraded' => 'Degradato',
        'failing' => 'In errore',
        'unknown' => 'Sconosciuto',
    ],

    'form' => [
        'new_heading' => 'Nuovo endpoint',
        'edit_heading' => 'Modifica endpoint',
        'name_label' => 'Nome',
        'name_hint' => 'Un\'etichetta facoltativa per riconoscere questo endpoint.',
        'url_label' => 'URL dell\'endpoint',
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_label' => 'Tipi di evento',
        'no_event_types' => 'Per questa applicazione non è ancora configurato alcun tipo di evento.',
        'active_label' => 'Attivo',
        'active_hint' => 'Le consegne vengono inviate solo mentre un endpoint è attivo.',
        'register' => 'Registra endpoint',
        'save' => 'Salva modifiche',
    ],

    'delete_dialog' => [
        'title' => 'Eliminare questo endpoint?',
        'description' => 'Questa azione rimuove definitivamente l\'endpoint e interrompe ogni consegna verso di esso. Non può essere annullata.',
        'confirm' => 'Elimina endpoint',
    ],

    'secret' => [
        'heading' => 'Chiave di firma',
        'hide' => 'Nascondi',
        'hidden_announcement' => 'Chiave di firma nascosta.',
        'notice' => 'Salva subito questa chiave — viene mostrata solo per poco tempo e non potrà essere recuperata in seguito. Usala per verificare la firma di ogni consegna.',
        // The whole sentence is one translatable unit so a locale can put the number
        // where its grammar wants it; the countdown re-renders it from this same string
        // on every tick.
        'countdown' => 'Questa chiave verrà nascosta automaticamente tra :seconds s.',
        'countdown_warning' => 'La chiave di firma verrà nascosta tra 10 secondi.',
        'copy' => 'Copia',
        'copied' => 'Copiato!',
        'previous' => 'Chiave precedente (ancora valida durante la rotazione)',
        'rotate' => 'Ruota chiave',
    ],

    'health_page' => [
        'heading' => 'Salute degli endpoint',
        'intro' => 'Come stanno i tuoi endpoint, valutati in base alla loro cronologia di consegne recente. Ricalcola per aggiornare un punteggio e vedere l\'ultimo tasso di successo, la latenza e la dimensione del campione.',
        'recompute' => 'Ricalcola',
        'recompute_all' => 'Ricalcola tutto',
        'never' => 'Mai',
    ],

    'transform' => [
        'heading' => 'Trasformazione del payload',
        'versioning_disabled' => 'Il versionamento del payload è attualmente disattivato. Puoi comunque modificare e salvare questa trasformazione; non modificherà le consegne finché il versionamento non viene attivato.',
        'rules' => 'Regole',
        'version_label' => 'Versione del payload',
        'version_hint' => 'Aggiunta al corpo come payload_version, così un destinatario può riconoscere la forma con cui è stato inviato.',
        'version_none' => 'Nessuna',
        'field_name_placeholder' => 'nome del campo',
        'include_label' => 'Includi campi',
        'include_hint' => 'Solo questi campi vengono mantenuti. Lascia vuoto per mantenerli tutti.',
        'add_include' => 'Aggiungi campo da includere',
        'exclude_label' => 'Escludi campi',
        'exclude_hint' => 'Questi campi vengono rimossi dal corpo.',
        'add_exclude' => 'Aggiungi campo da escludere',
        'rename_label' => 'Rinomina campi',
        'rename_hint' => 'Sposta un campo su un nuovo nome.',
        'rename_from_placeholder' => 'da',
        'rename_to_placeholder' => 'a',
        'add_rename' => 'Aggiungi rinomina',
        'rewrap_label' => 'Chiave di incapsulamento',
        'rewrap_hint' => 'Annida l\'intero corpo sotto un\'unica chiave. Lascia vuoto per inviarlo senza incapsulamento.',
        'rewrap_placeholder' => 'data',
        'save' => 'Salva trasformazione',
        'preview_heading' => 'Anteprima in tempo reale',
        'sample_label' => 'Payload di esempio',
        'sample_hint' => 'Modifica questo per vedere l\'anteprima con i tuoi dati.',
        'invalid_json' => 'Questo non è un oggetto JSON leggibile, quindi non c\'è nulla da mostrare in anteprima. Controlla se c\'è una virgola di troppo o mancano delle virgolette.',
        'input' => 'Ingresso',
        'output' => 'Uscita',
    ],

    'empty' => [
        'no_endpoints' => [
            'title' => 'Ancora nessun endpoint',
            'description' => 'Registra il tuo primo endpoint webhook per iniziare a ricevere eventi.',
        ],
        'no_endpoints_health' => [
            'title' => 'Ancora nessun endpoint',
            'description' => 'Registra un endpoint webhook per iniziare a monitorarne qui la salute.',
        ],
    ],

    'actions' => [
        'cancel' => 'Annulla',
        'remove' => 'Rimuovi',
        'back_to_endpoints' => 'Torna agli endpoint',
    ],

    // The cap is announced both as a warning toast and as an error on the URL field, so
    // the tenant reads the same sentence wherever it is refused.
    'limit_reached' => 'Hai raggiunto il limite di endpoint.',

    'toast' => [
        'endpoint_registered' => 'Endpoint registrato.',
        'endpoint_updated' => 'Endpoint aggiornato.',
        'endpoint_deleted' => 'Endpoint eliminato.',
        'secret_rotated' => 'Chiave di firma ruotata.',
        'health_recomputed' => 'Salute dell\'endpoint ricalcolata.',
        'health_recomputed_all' => 'Salute ricalcolata per tutti gli endpoint.',
        'transform_saved' => 'Trasformazione del payload salvata.',
    ],

    // The form's own validation copy, passed to the validator as custom messages and
    // attribute names, so a refused save speaks the reader's language rather than the
    // framework's default English lines.
    'validation' => [
        'name' => [
            'max' => 'Il nome non può superare i :max caratteri.',
        ],
        'url' => [
            'required' => 'È richiesto un URL dell\'endpoint.',
            'url' => 'Inserisci un URL dell\'endpoint valido.',
            // The SSRF guard's own message names the resolved host and address, which is
            // a probe oracle in a tenant-facing form. The reason the URL was refused is
            // always the same one a tenant can act on: it must be public and https.
            'blocked' => 'Questo URL non può essere usato come endpoint. Usa un URL https raggiungibile pubblicamente.',
        ],
        'event_types' => [
            'required' => 'Seleziona almeno un tipo di evento.',
            'min' => 'Seleziona almeno un tipo di evento.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface, so they live here with the visible
    // copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Vai al contenuto della pagina',
        'loading_endpoints' => 'Caricamento degli endpoint',
        'endpoints_table' => 'I tuoi endpoint webhook',
        'health_table' => 'Salute degli endpoint',
        'toggle_active' => 'Attiva o disattiva lo stato attivo di :url',
        'reveal_secret' => 'Mostra la chiave di firma per :url',
        'edit_endpoint' => 'Modifica endpoint :url',
        'edit_transform' => 'Modifica la trasformazione del payload per :url',
        'delete_endpoint' => 'Elimina endpoint :url',
        'recompute_health' => 'Ricalcola la salute per :url',
        'include_field' => 'Campo da includere :number',
        'remove_include_field' => 'Rimuovi il campo da includere :number',
        'exclude_field' => 'Campo da escludere :number',
        'remove_exclude_field' => 'Rimuovi il campo da escludere :number',
        'rename_source_field' => 'Campo di origine :number della rinomina',
        'rename_target_field' => 'Campo di destinazione :number della rinomina',
        'remove_rename_pair' => 'Rimuovi la coppia di rinomina :number',
        'output_preview' => 'Anteprima dell\'output trasformato',
        // A short, stable announcement beside the output pane. The pane itself is not a
        // live region: it is recomputed on every debounced keystroke, and announcing the
        // whole JSON body every 400 ms would make the editor unusable with a screen reader.
        'output_updated' => 'Anteprima aggiornata.',
    ],
];
