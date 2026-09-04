<?php

declare(strict_types=1);

return [
    // The browser window title for every dashboard page (the shared layout).
    'title' => 'Webhooks',

    'heading' => 'Webhooks',

    // Tab labels. The stored tab token is the array key and never changes; only the
    // label is translated — and it is stored display-ready, never cased by a CSS
    // text-transform the moment a host publishes and restyles the view.
    'tabs' => [
        'overview' => 'Resumen',
        'webhooks' => 'Webhooks',
        'queue' => 'Cola',
        'documentation' => 'Documentación',
    ],

    'kpis' => [
        'title' => 'De un vistazo',
        'total' => 'Total de webhooks enviados',
        'successful' => 'Exitosos',
        'failed' => 'Fallidos',
        'pending' => 'Pendientes',
        'retry_rate' => 'Tasa de reintentos',
    ],

    // Date patterns are translated, not just their month names: the ORDER differs by
    // locale (English leads with the month, Spanish with the day).
    'formats' => [
        'hour_bucket' => 'j M H:i',
        'absolute' => 'LLL z',

        // 'precise' is a second pattern because 'absolute' cannot separate two rows and the
        // accessible names need it to. lang/en/dashboard.php states the case in full, including
        // what it still does not cover.
        'precise' => 'LL LTS z',
    ],

    'api' => [
        'unsupported_window' => 'La ventana de métricas solicitada no es compatible. Ventanas admitidas: :windows.',
        'invalid_window' => 'La ventana seleccionada no es válida. Ventanas admitidas: :windows.',
    ],

    'activity' => [
        'title' => 'Actividad por hora',
        'delivered' => 'Entregados',
        'pending' => 'Pendientes',
        'failed' => 'Fallidos',
        'bar_title' => ':hour — :total en total',
    ],

    'latency' => [
        'title' => 'Latencia (ms)',
        'p95_trend' => 'Tendencia P95',
    ],

    'top_events' => [
        'title' => 'Eventos más frecuentes',
    ],

    'recent' => [
        'title' => 'Cola reciente',
    ],

    'setup' => [
        'title' => 'Endpoints',
        'total' => 'Total',
        'active' => 'Activos',
        'disabled' => 'Desactivados',
    ],

    'table' => [
        'event' => 'Evento',
        'status' => 'Estado',
        'attempt' => 'Intento',
        'code' => 'Código',
        'duration' => 'Duración',
        'when' => 'Cuándo',
        'actions' => 'Acciones',
        'replay' => 'Reenviar',
    ],

    'filters' => [
        'status' => 'Estado',
        'all_statuses' => 'Todos los estados',
        'event_type' => 'Tipo de evento',
        'event_type_placeholder' => 'Filtrar por tipo de evento',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. Lowercase, as
    // in the original design.
    // Shown when the rollup the counts come from has fallen behind the rows it summarizes --
    // twice the configured refresh cadence or more, so a run merely in progress never triggers it.
    'rollup_stale' => 'Los recuentos de entregas de esta página llevan :minutes minutos de retraso. Provienen de un rollup que actualiza `webhooks:refresh-metrics`; las latencias de al lado son en vivo, así que ambas discrepan hasta que ese comando vuelva a ejecutarse.',

    // These agree with a noun that is not in the string, and four of the five used to get it wrong.
    // They label one column of one table, and the thing they label is a delivery (la entrega),
    // feminine in this language. Four were masculine and the fifth feminine, so the column read as
    // machine translation on the most-read screen in the package.
    //
    // No guard can check this: agreement is a fact about a word that never appears beside them. The
    // noun is written here so the next translator has it.
    'status' => [
        'pending' => 'pendiente',
        'succeeded' => 'exitosa',
        'failed' => 'fallida',
        'exhausted' => 'agotada',
        'refused' => 'rechazada',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'Pendiente',
        'succeeded' => 'Exitoso',
        'failed' => 'Fallido',
        'exhausted' => 'Agotado',
        'refused' => 'Rechazada',
    ],

    'drawer' => [
        'close' => 'Cerrar',
        'attempt' => 'Intento :number',
        'http' => 'HTTP :code',
        'queued' => 'En cola',
        'delivered' => 'Entregado',
        'payload' => 'Payload',
        'replay' => 'Reenviar entrega',
        // Shown INSTEAD of the values when the payload ability denies the read. It has
        // to say why: a panel that just stops after its heading reads as a defect.
        'payload_redacted' => 'Los valores están ocultos. Se muestra la estructura para que puedas comprobar la forma del cuerpo sin leer los datos que contiene.',
        'payload_offloaded' => 'Este cuerpo era demasiado grande para el registro y se movió al disco :disk. Lo que se muestra aquí es el fragmento que guardó el registro, no el cuerpo entregado.',
        'payload_hidden' => 'El cuerpo está oculto. No tienes permiso para ver los payloads de las entregas.',
    ],

    'empty' => [
        'no_activity' => [
            'title' => 'Aún no hay actividad',
            'description' => 'Las entregas de esta ventana aparecerán aquí desglosadas por hora.',
        ],
        'no_events' => [
            'title' => 'Aún no hay eventos',
            'description' => 'Aquí se clasificarán tus tipos de evento más frecuentes.',
        ],
        'no_deliveries' => [
            'title' => 'Aún no hay entregas',
            'description' => 'Las entregas irán apareciendo aquí a medida que se envíen tus eventos.',
        ],
        'no_deliveries_found' => [
            'title' => 'No se encontraron entregas',
            'description' => 'Ninguna entrega coincide con los filtros actuales. Quita un filtro para ver más.',
        ],
        'no_endpoints' => [
            'title' => 'No hay endpoints registrados',
            'description' => 'Registra un endpoint de webhook para empezar a recibir entregas.',
        ],
    ],

    'docs' => [
        'title' => 'Documentación',
        'body' => 'Registra endpoints, firma cada entrega con el esquema Standard Webhooks y reenvía cualquier entrega desde este panel. Consulta el README del paquete para ver la referencia de configuración completa y el catálogo de eventos.',
    ],

    'toast' => [
        'redelivery_queued' => 'Reenvío añadido a la cola.',
        'endpoint_disabled' => 'Este endpoint está desactivado. Vuelve a activarlo antes de reenviarle una entrega.',
    ],

    // Strings a reader never sees but a screen reader always announces. An
    // untranslated accessible name is an untranslated interface, so they live here
    // with the visible copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Saltar al contenido del panel',
        'time_window' => 'Ventana de tiempo',
        'sections' => 'Secciones del panel',
        'retry_rate' => 'Tasa de reintentos',
        'deliveries_per_hour' => 'Entregas por hora',
        'hour_summary' => [
            // Four choice fragments rather than one sentence with four numbers in it: agreement is
            // decided by the number, `trans_choice` takes one count per string, and there are four.
            // lang/en/dashboard.php states the case in full, including why English, German and
            // Dutch carry identical forms on both sides.
            'total' => '{0} :count en total|{1} :count en total|[2,*] :count en total',
            'delivered' => '{0} :count entregadas|{1} :count entregada|[2,*] :count entregadas',
            'pending' => '{0} :count pendientes|{1} :count pendiente|[2,*] :count pendientes',
            'failed' => '{0} :count fallidas|{1} :count fallida|[2,*] :count fallidas',
        ],
        'latency_trend' => 'Tendencia de latencia P95 por hora',
        'latency_bar' => ':hour: :value ms',
        'recent_deliveries_table' => 'Entregas de webhook recientes',
        'deliveries_table' => 'Entregas de webhook',
        'replay_delivery' => ':label — :event · :endpoint · :at',
        'view_delivery' => 'Ver detalles de la entrega de :event a :endpoint del :at',
        'delivery_details' => 'Detalles de la entrega',
        'close_details' => 'Cerrar detalles',
        'loading_kpis' => 'Cargando métricas clave',
        'loading_chart' => 'Cargando gráfico de actividad',
        'loading_panel' => 'Cargando panel',
        'loading_deliveries' => 'Cargando entregas',
    ],
];
