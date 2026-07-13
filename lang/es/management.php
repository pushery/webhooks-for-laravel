<?php

declare(strict_types=1);

// Copy for the publishable management stubs (the neutral views and their WireKit
// twins). Both variants render the same screens, so they read the same keys — a
// label fixed here stays fixed in both, and a host that restyles one variant keeps
// the wording of the other.
return [
    'form' => [
        'name_label' => 'Nombre',
        'url_label' => 'URL del endpoint',
        // An example URL, not prose, but it reaches the reader as a placeholder, so a
        // locale can point it at a domain its audience recognizes.
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_legend' => 'Tipos de evento',
        // The file path travels as a placeholder so a locale is free to put it wherever
        // its grammar wants it.
        'event_types_empty' => 'Configura los tipos de evento en :file.',
        'submit' => 'Registrar endpoint',
    ],

    'secret' => [
        'heading' => 'Clave de firma (se muestra una sola vez — guárdala ahora)',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'events' => 'Eventos',
        'status' => 'Estado',
        'event' => 'Evento',
        'attempt' => 'Intento',
        'code' => 'Código',
        'when' => 'Cuándo',
        // The actions column shows no visible header, but a column still needs an
        // accessible name — it is read out, so it is translated.
        'actions' => 'Acciones',
    ],

    'subscription' => [
        'active' => 'Activo',
        'disabled' => 'Desactivado',
        'enable' => 'Activar',
        'disable' => 'Desactivar',
        'delete' => 'Eliminar',
    ],

    // Deleting an endpoint is irreversible and stops a live integration, so both stubs
    // confirm it first — the WireKit variant through an alert-dialog, the neutral one
    // through the browser confirm.
    'delete_dialog' => [
        'title' => '¿Eliminar este endpoint?',
        'description' => 'El endpoint deja de recibir webhooks de inmediato y su clave de firma se destruye. No se puede deshacer.',
        'confirm' => 'Eliminar endpoint',
    ],

    'actions' => [
        'cancel' => 'Cancelar',
    ],

    'empty' => [
        'no_subscriptions' => [
            'title' => 'Aún no hay endpoints',
            'description' => 'Registra tu primer endpoint arriba para empezar a entregar webhooks.',
        ],
        'no_deliveries' => [
            'title' => 'No se encontraron entregas',
            'description' => 'Las entregas aparecen aquí a medida que se envían tus eventos. Quita un filtro para ver más.',
        ],
    ],

    'deliveries' => [
        'redeliver' => 'Reenviar',
        'ping' => 'Probar',
    ],

    'filters' => [
        // The filter controls hide their labels visually, so these strings reach
        // sighted readers only through assistive technology — they are translated for
        // exactly the same reason a visible label is.
        'status' => 'Estado',
        'all_statuses' => 'Todos los estados',
        'event_type' => 'Tipo de evento',
        'event_type_placeholder' => 'Filtrar por tipo de evento',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. Lowercase, as
    // in the original design.
    'status' => [
        'pending' => 'pendiente',
        'succeeded' => 'exitoso',
        'failed' => 'fallido',
        'exhausted' => 'agotado',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'Pendiente',
        'succeeded' => 'Exitoso',
        'failed' => 'Fallido',
        'exhausted' => 'Agotado',
    ],

    'messages' => [
        // Shown when a replay is asked for an endpoint that is switched off — by its
        // tenant, or by the circuit breaker after too many failures.
        'endpoint_disabled' => 'Este endpoint está desactivado. Vuelve a activarlo antes de reenviarle una entrega.',
    ],

    'validation' => [
        'url' => [
            // What the reader gets when the SSRF guard refuses the destination. The
            // guard's own message stays untranslated: it is an operator diagnostic for
            // the log, and it would tell a stranger which hosts resolve where.
            'blocked' => 'Esta URL no se puede usar como endpoint. Usa una URL https accesible públicamente.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface.
    'a11y' => [
        'delete_subscription' => 'Eliminar el endpoint :url',
    ],
];
