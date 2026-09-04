<?php

declare(strict_types=1);

// Copy for the publishable management stubs (the neutral views and their WireKit
// twins). Both variants render the same screens, so they read the same keys — a
// label fixed here stays fixed in both, and a host that restyles one variant keeps
// the wording of the other.
return [
    'form' => [
        'error_summary' => '{1} Het formulier bevat één fout.|[2,*] Het formulier bevat :count fouten.',
        'name_label' => 'Naam',
        'url_label' => 'Endpoint-URL',
        // An example URL, not prose, but it reaches the reader as a placeholder, so a
        // locale can point it at a domain its audience recognizes.
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_legend' => 'Event-types',
        // The file path travels as a placeholder so a locale is free to put it wherever
        // its grammar wants it.
        'event_types_empty' => 'Configureer de event-types in :file.',
        'submit' => 'Endpoint registreren',
        // Hetzelfde formulier, zodra er een endpoint openstaat om te bewerken.
        'submit_update' => 'Wijzigingen opslaan',
        // Alleen bij bewerken: een registratie is per definitie actief.
        'active_label' => 'Actief',
    ],

    'secret' => [
        'heading' => 'Ondertekeningssleutel (wordt maar één keer getoond — sla hem nu op)',
        // Een rotatie zegt iets wat een registratie niet zegt, en de lezer moet het weten: de
        // vorige sleutel blijft geldig tot het rotatievenster sluit. Precies daarom kun je
        // tijdens een incident meteen roteren.
        'rotated_heading' => 'Nieuwe ondertekeningssleutel (wordt maar één keer getoond — sla hem nu op). De vorige sleutel blijft geldig tot het rotatievenster sluit.',
        // The one-time secret exists in a single response — this console has no reveal
        // window to ask again with — so the copy control is what stands between the reader
        // and a rotation nobody needed.
        'copy' => 'Kopiëren',
        'copied' => 'Gekopieerd!',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'events' => 'Events',
        'status' => 'Status',
        'event' => 'Event',
        'attempt' => 'Poging',
        'code' => 'Code',
        'when' => 'Wanneer',
        // The actions column shows no visible header, but a column still needs an
        // accessible name — it is read out, so it is translated.
        'actions' => 'Acties',
    ],

    'subscription' => [
        'active' => 'Actief',
        'disabled' => 'Uitgeschakeld',
        // Health bands, worded exactly as the self-service matrix words them: one
        // package, one vocabulary for the same state.
        'degraded' => 'Verminderd',
        'failing' => 'Falend',
        // Why an endpoint is off, not just that it is: the breaker and a person write
        // the same two columns, and only the failure streak separates them.
        'auto_disabled' => 'Automatisch uitgeschakeld na :count opeenvolgende fouten',
        'enable' => 'Inschakelen',
        'disable' => 'Uitschakelen',
        'edit' => 'Bewerken',
        'rotate' => 'Sleutel roteren',
        'delete' => 'Verwijderen',
    ],

    // Deleting an endpoint is irreversible and stops a live integration, so both stubs
    // confirm it first — the WireKit variant through an alert-dialog, the neutral one
    // through the browser confirm.
    'delete_dialog' => [
        'title' => 'Dit endpoint verwijderen?',
        'description' => 'Het endpoint ontvangt vanaf nu geen webhooks meer en de ondertekeningssleutel wordt vernietigd. Dit kan niet ongedaan worden gemaakt.',
        'confirm' => 'Endpoint verwijderen',
    ],

    // Roteren zet de oude sleutel onder een termijn in plaats van hem meteen ongeldig te
    // maken, maar het blijft een wijziging die elke ontvanger moet volgen — beide stubs
    // bevestigen hem dus, net als het verwijderen ernaast.
    'rotate_dialog' => [
        'title' => 'Deze ondertekeningssleutel roteren?',
        'description' => 'Er wordt meteen een nieuwe sleutel uitgegeven, die één keer wordt getoond. De huidige sleutel blijft geldig tot het rotatievenster sluit — werk de ontvanger daarvoor bij.',
        'confirm' => 'Sleutel roteren',
    ],

    'actions' => [
        'cancel' => 'Annuleren',
    ],

    'empty' => [
        'no_subscriptions' => [
            'title' => 'Nog geen endpoints',
            'description' => 'Registreer hierboven je eerste endpoint om webhooks af te leveren.',
        ],
        'no_deliveries' => [
            'title' => 'Geen leveringen gevonden',
            'description' => 'Zodra je events worden verzonden, verschijnen de leveringen hier. Verwijder een filter om meer te zien.',
        ],
    ],

    'deliveries' => [
        'redeliver' => 'Opnieuw versturen',
        'ping' => 'Test versturen',
    ],

    'filters' => [
        // The filter controls hide their labels visually, so these strings reach
        // sighted readers only through assistive technology — they are translated for
        // exactly the same reason a visible label is.
        'status' => 'Status',
        'all_statuses' => 'Alle statussen',
        'event_type' => 'Event-type',
        'event_type_placeholder' => 'Filteren op event-type',
        'endpoint' => 'Endpoint',
        'all_endpoints' => 'Alle endpoints',
        'from' => 'Van',
        'until' => 'Tot en met',
        'endpoints_truncated' => 'Hier worden alleen de eerste endpoints aangeboden. Ontbreekt degene die je zoekt, filter dan op event-type of pas deze stub aan.',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. Lowercase, as
    // in the original design.
    'status' => [
        'pending' => 'in afwachting',
        'succeeded' => 'geslaagd',
        'failed' => 'mislukt',
        'exhausted' => 'uitgeput',
        'refused' => 'geweigerd',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'In afwachting',
        'succeeded' => 'Geslaagd',
        'failed' => 'Mislukt',
        'exhausted' => 'Uitgeput',
        'refused' => 'Geweigerd',
    ],

    'messages' => [
        // Shown when a replay is asked for an endpoint that is switched off — by its
        // tenant, or by the circuit breaker after too many failures.
        'endpoint_disabled' => 'Dit endpoint is uitgeschakeld. Schakel het weer in voordat je een levering opnieuw verstuurt.',

        // Getoond als een testping wordt geweigerd omdat het minuutquotum op is.
        'ping_throttled' => 'Dit endpoint heeft zijn testpings er voorlopig op zitten. Probeer het over :seconds seconde(n) opnieuw.',
    ],

    'validation' => [
        'event_types' => [
            'string' => 'Een event-type moet een naam zijn, geen getal en geen lijst.',
            // An operator registers a GLOBAL endpoint here, so a type nothing publishes
            // costs every tenant's events for it rather than one tenant's.
            'in' => 'Dit event-type publiceert deze applicatie niet.',
        ],
        'url' => [
            // The scheme narrowing on the rule ('url:http,https') is what produces this,
            // and without a line here the operator console fell back to the framework's
            // own English 'must be a valid URL' -- for a form whose every other message
            // is translated.
            'url' => 'Voer een geldige endpoint-URL in. Die moet met http:// of https:// beginnen.',
            // What the reader gets when the SSRF guard refuses the destination. The
            // guard's own message stays untranslated: it is an operator diagnostic for
            // the log, and it would tell a stranger which hosts resolve where.
            'blocked' => 'Deze URL kan niet als endpoint worden gebruikt. Gebruik een openbaar bereikbare https-URL.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface.
    'a11y' => [
        'subscriptions_table' => 'Je webhook-endpoints',
        'delivery_log_table' => 'Afleverlogboek',
        'edit_subscription' => 'Endpoint :url bewerken',
        'rotate_subscription' => ':label — :url',
        'redeliver_delivery' => ':label — :event · :endpoint · :at',
        'toggle_subscription' => ':label — :url',
        'ping_subscription' => ':label — :url',
        'delete_subscription' => 'Endpoint :url verwijderen',
    ],
];
