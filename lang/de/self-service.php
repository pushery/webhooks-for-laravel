<?php

declare(strict_types=1);

return [
    // The browser window title for every self-service page (the shared layout).
    'title' => 'Webhook-Endpunkte',

    'page' => [
        'heading' => 'Webhook-Endpunkte',
        'intro' => 'Registriere die Endpunkte, an denen deine Anwendung Webhooks empfangen soll, wähle die Events aus, auf die jeder Endpunkt hört, und verwalte seinen Signaturschlüssel.',
        'health_link' => 'Endpunkt-Zustand',
    ],

    'list' => [
        'heading' => 'Deine Endpunkte',
        'new_endpoint' => 'Neuer Endpunkt',
        'cap_reached' => 'Endpunkt-Limit erreicht.',
        'secret' => 'Schlüssel',
        'edit' => 'Bearbeiten',
        'transform' => 'Transformation',
        'delete' => 'Löschen',
        'active' => 'Aktiv',
        'disabled' => 'Deaktiviert',
    ],

    'table' => [
        'endpoint' => 'Endpunkt',
        'health' => 'Zustand',
        'events' => 'Events',
        'status' => 'Status',
        'score' => 'Punktzahl',
        'success_rate' => 'Erfolgsquote',
        'p95' => 'p95',
        'sample' => 'Stichprobe',
        'as_of' => 'Stand',
        'actions' => 'Aktionen',
    ],

    // Badge labels for the stored health band. The key is the persisted health_status
    // value and is never translated; only the label a reader sees is.
    'health' => [
        'healthy' => 'Gesund',
        'degraded' => 'Beeinträchtigt',
        'failing' => 'Fehlerhaft',
        'unknown' => 'Unbekannt',
    ],

    'form' => [
        'new_heading' => 'Neuer Endpunkt',
        'edit_heading' => 'Endpunkt bearbeiten',
        'name_label' => 'Name',
        'name_hint' => 'Ein optionaler Name, an dem du diesen Endpunkt wiedererkennst.',
        'url_label' => 'Endpunkt-URL',
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_label' => 'Event-Typen',
        'no_event_types' => 'Für diese Anwendung sind noch keine Event-Typen konfiguriert.',
        'active_label' => 'Aktiv',
        'active_hint' => 'Zustellungen werden nur gesendet, solange ein Endpunkt aktiv ist.',
        'register' => 'Endpunkt registrieren',
        'save' => 'Änderungen speichern',
    ],

    'delete_dialog' => [
        'title' => 'Diesen Endpunkt löschen?',
        'description' => 'Der Endpunkt wird dauerhaft entfernt und es gehen keine Zustellungen mehr an ihn. Das lässt sich nicht rückgängig machen.',
        'confirm' => 'Endpunkt löschen',
    ],

    'secret' => [
        'heading' => 'Signaturschlüssel',
        'hide' => 'Ausblenden',
        'hidden_announcement' => 'Signaturschlüssel ausgeblendet.',
        'notice' => 'Speichere diesen Schlüssel jetzt — er wird nur kurz angezeigt und lässt sich später nicht erneut abrufen. Prüfe damit die Signatur jeder Zustellung.',
        // The whole sentence is one translatable unit so a locale can put the number
        // where its grammar wants it; the countdown re-renders it from this same string
        // on every tick.
        'countdown' => 'Dieser Schlüssel wird in :seconds s automatisch ausgeblendet.',
        'countdown_warning' => 'Der Signaturschlüssel wird in 10 Sekunden ausgeblendet.',
        'copy' => 'Kopieren',
        'copied' => 'Kopiert!',
        'previous' => 'Vorheriger Schlüssel (während der Rotation weiterhin gültig)',
        'rotate' => 'Schlüssel rotieren',
    ],

    'health_page' => [
        'heading' => 'Endpunkt-Zustand',
        'intro' => 'Wie es um deine Endpunkte steht, bewertet anhand ihrer jüngsten Zustellungen. Berechne neu, um eine Punktzahl zu aktualisieren und die aktuelle Erfolgsquote, Latenz und Stichprobengröße zu sehen.',
        'recompute' => 'Neu berechnen',
        'recompute_all' => 'Alle neu berechnen',
        'never' => 'Nie',
    ],

    'transform' => [
        'heading' => 'Payload-Transformation',
        'versioning_disabled' => 'Die Payload-Versionierung ist derzeit deaktiviert. Du kannst diese Transformation trotzdem bearbeiten und speichern; sie verändert Zustellungen erst, wenn die Versionierung eingeschaltet ist.',
        'rules' => 'Regeln',
        'version_label' => 'Payload-Version',
        'version_hint' => 'Wird als payload_version in den Body geschrieben, damit ein Empfänger die Form der gesendeten Daten erkennt.',
        'version_none' => 'Keine',
        'field_name_placeholder' => 'Feldname',
        'include_label' => 'Felder einschließen',
        'include_hint' => 'Nur diese Felder bleiben erhalten. Leer lassen, um alle zu behalten.',
        'add_include' => 'Feld zum Einschließen hinzufügen',
        'exclude_label' => 'Felder ausschließen',
        'exclude_hint' => 'Diese Felder werden aus dem Body entfernt.',
        'add_exclude' => 'Feld zum Ausschließen hinzufügen',
        'rename_label' => 'Felder umbenennen',
        'rename_hint' => 'Ein Feld auf einen neuen Namen verschieben.',
        'rename_from_placeholder' => 'von',
        'rename_to_placeholder' => 'nach',
        'add_rename' => 'Umbenennung hinzufügen',
        'rewrap_label' => 'Umschließender Schlüssel',
        'rewrap_hint' => 'Den gesamten Body unter einem einzigen Schlüssel verschachteln. Leer lassen, um ihn unverpackt zu senden.',
        'rewrap_placeholder' => 'data',
        'save' => 'Transformation speichern',
        'preview_heading' => 'Live-Vorschau',
        'sample_label' => 'Beispiel-Payload',
        'sample_hint' => 'Bearbeite dies, um die Vorschau mit deinen eigenen Daten zu sehen.',
        'invalid_json' => 'Das ist kein lesbares JSON-Objekt, deshalb gibt es nichts zur Vorschau. Prüfe auf ein überzähliges Komma oder ein fehlendes Anführungszeichen.',
        'input' => 'Eingabe',
        'output' => 'Ausgabe',
    ],

    'empty' => [
        'no_endpoints' => [
            'title' => 'Noch keine Endpunkte',
            'description' => 'Registriere deinen ersten Webhook-Endpunkt, um Events zu empfangen.',
        ],
        'no_endpoints_health' => [
            'title' => 'Noch keine Endpunkte',
            'description' => 'Registriere einen Webhook-Endpunkt, um seinen Zustand hier zu verfolgen.',
        ],
    ],

    'actions' => [
        'cancel' => 'Abbrechen',
        'remove' => 'Entfernen',
        'back_to_endpoints' => 'Zurück zu den Endpunkten',
    ],

    // The cap is announced both as a warning toast and as an error on the URL field, so
    // the tenant reads the same sentence wherever it is refused.
    'limit_reached' => 'Du hast dein Endpunkt-Limit erreicht.',

    'toast' => [
        'endpoint_registered' => 'Endpunkt registriert.',
        'endpoint_updated' => 'Endpunkt aktualisiert.',
        'endpoint_deleted' => 'Endpunkt gelöscht.',
        'secret_rotated' => 'Signaturschlüssel rotiert.',
        'health_recomputed' => 'Endpunkt-Zustand neu berechnet.',
        'health_recomputed_all' => 'Endpunkt-Zustand für alle Endpunkte neu berechnet.',
        'transform_saved' => 'Payload-Transformation gespeichert.',
    ],

    // The form's own validation copy, passed to the validator as custom messages and
    // attribute names, so a refused save speaks the reader's language rather than the
    // framework's default English lines.
    'validation' => [
        'name' => [
            'max' => 'Der Name darf höchstens :max Zeichen lang sein.',
        ],
        'url' => [
            'required' => 'Eine Endpunkt-URL ist erforderlich.',
            'url' => 'Gib eine gültige Endpunkt-URL ein.',
            // The SSRF guard's own message names the resolved host and address, which is
            // a probe oracle in a tenant-facing form. The reason the URL was refused is
            // always the same one a tenant can act on: it must be public and https.
            'blocked' => 'Diese URL kann nicht als Endpunkt verwendet werden. Verwende eine öffentlich erreichbare https-URL.',
        ],
        'event_types' => [
            'required' => 'Wähle mindestens einen Event-Typ aus.',
            'min' => 'Wähle mindestens einen Event-Typ aus.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface, so they live here with the visible
    // copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Direkt zum Seiteninhalt',
        'loading_endpoints' => 'Endpunkte werden geladen',
        'endpoints_table' => 'Deine Webhook-Endpunkte',
        'health_table' => 'Endpunkt-Zustand',
        'toggle_active' => 'Aktiv-Status für :url umschalten',
        'reveal_secret' => 'Signaturschlüssel für :url anzeigen',
        'edit_endpoint' => 'Endpunkt :url bearbeiten',
        'edit_transform' => 'Payload-Transformation für :url bearbeiten',
        'delete_endpoint' => 'Endpunkt :url löschen',
        'recompute_health' => 'Zustand für :url neu berechnen',
        'include_field' => 'Einzuschließendes Feld :number',
        'remove_include_field' => 'Einzuschließendes Feld :number entfernen',
        'exclude_field' => 'Auszuschließendes Feld :number',
        'remove_exclude_field' => 'Auszuschließendes Feld :number entfernen',
        'rename_source_field' => 'Quellfeld :number der Umbenennung',
        'rename_target_field' => 'Zielfeld :number der Umbenennung',
        'remove_rename_pair' => 'Umbenennung :number entfernen',
        'output_preview' => 'Vorschau der transformierten Ausgabe',
        // Eine kurze, gleichbleibende Ansage neben der Ausgabe. Die Ausgabe selbst ist
        // kein Live-Bereich: sie wird bei jedem entprellten Tastendruck neu berechnet, und
        // das vollständige JSON alle 400 ms vorzulesen macht den Editor unbenutzbar.
        'output_updated' => 'Vorschau aktualisiert.',
    ],
];
