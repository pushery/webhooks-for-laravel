<?php

declare(strict_types=1);

return [
    // The browser window title for every self-service page (the shared layout).
    'title' => 'Webhook-endpoints',

    'page' => [
        'heading' => 'Webhook-endpoints',
        'intro' => 'Registreer de endpoints waarop jouw applicatie webhooks moet ontvangen, kies de events waarnaar elk endpoint luistert en beheer de bijbehorende ondertekeningssleutel.',
        'health_link' => 'Endpoint-gezondheid',
    ],

    'list' => [
        'heading' => 'Jouw endpoints',
        'new_endpoint' => 'Nieuw endpoint',
        'cap_reached' => 'Endpoint-limiet bereikt.',
        'secret' => 'Sleutel',
        'edit' => 'Bewerken',
        'transform' => 'Transformatie',
        'delete' => 'Verwijderen',
        'active' => 'Actief',
        'disabled' => 'Uitgeschakeld',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'health' => 'Gezondheid',
        'events' => 'Events',
        'status' => 'Status',
        'score' => 'Score',
        'success_rate' => 'Slagingspercentage',
        'p95' => 'p95',
        'sample' => 'Steekproef',
        'as_of' => 'Per',
        'actions' => 'Acties',
    ],

    // Badge labels for the stored health band. The key is the persisted health_status
    // value and is never translated; only the label a reader sees is.
    'health' => [
        'healthy' => 'Gezond',
        'degraded' => 'Verminderd',
        'failing' => 'Falend',
        'unknown' => 'Onbekend',
    ],

    'form' => [
        'new_heading' => 'Nieuw endpoint',
        'edit_heading' => 'Endpoint bewerken',
        'name_label' => 'Naam',
        'name_hint' => 'Een optioneel label waaraan je dit endpoint herkent.',
        'url_label' => 'Endpoint-URL',
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_label' => 'Event-types',
        'no_event_types' => 'Voor deze applicatie zijn nog geen event-types geconfigureerd.',
        'active_label' => 'Actief',
        'active_hint' => 'Leveringen worden alleen verzonden zolang een endpoint actief is.',
        'register' => 'Endpoint registreren',
        'save' => 'Wijzigingen opslaan',
    ],

    'delete_dialog' => [
        'title' => 'Dit endpoint verwijderen?',
        'description' => 'Hiermee wordt het endpoint definitief verwijderd en stopt elke levering ernaartoe. Dit kan niet ongedaan worden gemaakt.',
        'confirm' => 'Endpoint verwijderen',
    ],

    'secret' => [
        'heading' => 'Ondertekeningssleutel',
        'hide' => 'Verbergen',
        'hidden_announcement' => 'Ondertekeningssleutel verborgen.',
        'notice' => 'Sla deze sleutel nu op — hij wordt maar kort getoond en kan later niet meer worden opgehaald. Controleer er de handtekening van elke levering mee.',
        // The whole sentence is one translatable unit so a locale can put the number
        // where its grammar wants it; the countdown re-renders it from this same string
        // on every tick.
        'countdown' => 'Deze sleutel wordt over :seconds s automatisch verborgen.',
        'countdown_warning' => 'De ondertekeningssleutel wordt over 10 seconden verborgen.',
        'copy' => 'Kopiëren',
        'copied' => 'Gekopieerd!',
        'previous' => 'Vorige sleutel (tijdens de rotatie nog steeds geldig)',
        'rotate' => 'Sleutel roteren',
    ],

    'health_page' => [
        'heading' => 'Endpoint-gezondheid',
        'intro' => 'Hoe elk van jouw endpoints ervoor staat, beoordeeld op basis van de recente leveringsgeschiedenis. Bereken opnieuw om een score te verversen en het laatste slagingspercentage, de latentie en de steekproefgrootte te zien.',
        'recompute' => 'Opnieuw berekenen',
        'recompute_all' => 'Alles opnieuw berekenen',
        'never' => 'Nooit',
    ],

    'transform' => [
        'heading' => 'Payload-transformatie',
        'versioning_disabled' => 'Payload-versionering is momenteel uitgeschakeld. Je kunt deze transformatie nog steeds bewerken en opslaan; ze past leveringen pas aan zodra versionering is ingeschakeld.',
        'rules' => 'Regels',
        'version_label' => 'Payload-versie',
        'version_hint' => 'Wordt als payload_version aan de body toegevoegd, zodat een ontvanger de vorm van de verzonden gegevens kan herkennen.',
        'version_none' => 'Geen',
        'field_name_placeholder' => 'veldnaam',
        'include_label' => 'Velden opnemen',
        'include_hint' => 'Alleen deze velden blijven behouden. Laat leeg om ze allemaal te behouden.',
        'add_include' => 'Op te nemen veld toevoegen',
        'exclude_label' => 'Velden uitsluiten',
        'exclude_hint' => 'Deze velden worden uit de body verwijderd.',
        'add_exclude' => 'Uit te sluiten veld toevoegen',
        'rename_label' => 'Velden hernoemen',
        'rename_hint' => 'Een veld naar een nieuwe naam verplaatsen.',
        'rename_from_placeholder' => 'van',
        'rename_to_placeholder' => 'naar',
        'add_rename' => 'Hernoeming toevoegen',
        'rewrap_label' => 'Omhullende sleutel',
        'rewrap_hint' => 'Nest de hele body onder één enkele sleutel. Laat leeg om hem onverpakt te versturen.',
        'rewrap_placeholder' => 'data',
        'save' => 'Transformatie opslaan',
        'preview_heading' => 'Live-voorbeeld',
        'sample_label' => 'Voorbeeld-payload',
        'sample_hint' => 'Bewerk dit om het voorbeeld met je eigen gegevens te bekijken.',
        'invalid_json' => 'Dit is geen leesbaar JSON-object, dus er valt niets te tonen. Controleer op een overbodige komma of een ontbrekend aanhalingsteken.',
        'input' => 'Invoer',
        'output' => 'Uitvoer',
    ],

    'empty' => [
        'no_endpoints' => [
            'title' => 'Nog geen endpoints',
            'description' => 'Registreer je eerste webhook-endpoint om events te ontvangen.',
        ],
        'no_endpoints_health' => [
            'title' => 'Nog geen endpoints',
            'description' => 'Registreer een webhook-endpoint om de gezondheid ervan hier te volgen.',
        ],
    ],

    'actions' => [
        'cancel' => 'Annuleren',
        'remove' => 'Verwijderen',
        'back_to_endpoints' => 'Terug naar endpoints',
    ],

    // The cap is announced both as a warning toast and as an error on the URL field, so
    // the tenant reads the same sentence wherever it is refused.
    'limit_reached' => 'Je hebt je endpoint-limiet bereikt.',

    'toast' => [
        'endpoint_registered' => 'Endpoint geregistreerd.',
        'endpoint_updated' => 'Endpoint bijgewerkt.',
        'endpoint_deleted' => 'Endpoint verwijderd.',
        'secret_rotated' => 'Ondertekeningssleutel geroteerd.',
        'health_recomputed' => 'Endpoint-gezondheid opnieuw berekend.',
        'health_recomputed_all' => 'Endpoint-gezondheid voor alle endpoints opnieuw berekend.',
        'transform_saved' => 'Payload-transformatie opgeslagen.',
    ],

    // The form's own validation copy, passed to the validator as custom messages and
    // attribute names, so a refused save speaks the reader's language rather than the
    // framework's default English lines.
    'validation' => [
        'name' => [
            'max' => 'De naam mag niet langer zijn dan :max tekens.',
        ],
        'url' => [
            'required' => 'Een endpoint-URL is verplicht.',
            'url' => 'Voer een geldige endpoint-URL in.',
            'max' => 'De endpoint-URL mag niet langer zijn dan :max tekens.',
            // The SSRF guard's own message names the resolved host and address, which is
            // a probe oracle in a tenant-facing form. The reason the URL was refused is
            // always the same one a tenant can act on: it must be public and https.
            'blocked' => 'Deze URL kan niet als endpoint worden gebruikt. Gebruik een openbaar bereikbare https-URL.',
        ],
        'event_types' => [
            'required' => 'Selecteer minstens één event-type.',
            'min' => 'Selecteer minstens één event-type.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface, so they live here with the visible
    // copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Direct naar de pagina-inhoud',
        'loading_endpoints' => 'Endpoints worden geladen',
        'endpoints_table' => 'Jouw webhook-endpoints',
        'health_table' => 'Endpoint-gezondheid',
        'toggle_active' => 'Actief-status voor :url omschakelen',
        'reveal_secret' => 'Ondertekeningssleutel voor :url tonen',
        'edit_endpoint' => 'Endpoint :url bewerken',
        'edit_transform' => 'Payload-transformatie voor :url bewerken',
        'delete_endpoint' => 'Endpoint :url verwijderen',
        'recompute_health' => 'Gezondheid voor :url opnieuw berekenen',
        'include_field' => 'Op te nemen veld :number',
        'remove_include_field' => 'Op te nemen veld :number verwijderen',
        'exclude_field' => 'Uit te sluiten veld :number',
        'remove_exclude_field' => 'Uit te sluiten veld :number verwijderen',
        'rename_source_field' => 'Bronveld :number van de hernoeming',
        'rename_target_field' => 'Doelveld :number van de hernoeming',
        'remove_rename_pair' => 'Hernoemingspaar :number verwijderen',
        'output_preview' => 'Voorbeeld van de getransformeerde uitvoer',
        // A short, stable announcement beside the output pane. The pane itself is not a
        // live region: it is recomputed on every debounced keystroke, and announcing the
        // whole JSON body every 400 ms would make the editor unusable with a screen reader.
        'output_updated' => 'Voorbeeld bijgewerkt.',
    ],
];
