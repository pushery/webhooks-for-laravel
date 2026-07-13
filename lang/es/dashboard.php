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
        'total' => 'Total de webhooks enviados',
        'successful' => 'Exitosos',
        'failed' => 'Fallidos',
        'pending' => 'Pendientes',
        'retry_rate' => 'Tasa de reintentos',
    ],

    // Date patterns are translated, not just their month names: the ORDER differs by
    // locale (English leads with the month, Spanish with the day).
    'formats' => [
        'hour_bucket' => 'j M H:00',
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

    'drawer' => [
        'close' => 'Cerrar',
        'attempt' => 'Intento :number',
        'http' => 'HTTP :code',
        'queued' => 'En cola',
        'delivered' => 'Entregado',
        'payload' => 'Payload',
        'replay' => 'Reenviar entrega',
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
        'hour_summary' => ':hour: :total en total, :delivered entregados, :pending pendientes, :failed fallidos',
        'latency_trend' => 'Tendencia de latencia P95 por hora',
        'recent_deliveries_table' => 'Entregas de webhook recientes',
        'deliveries_table' => 'Entregas de webhook',
        'replay_delivery' => 'Reenviar entrega de :event',
        'view_delivery' => 'Ver detalles de la entrega de :event',
        'delivery_details' => 'Detalles de la entrega',
        'close_details' => 'Cerrar detalles',
        'loading_kpis' => 'Cargando métricas clave',
        'loading_chart' => 'Cargando gráfico de actividad',
        'loading_panel' => 'Cargando panel',
        'loading_deliveries' => 'Cargando entregas',
    ],
];
