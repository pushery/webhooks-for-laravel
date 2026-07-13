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
        'overview' => 'Visão geral',
        'webhooks' => 'Webhooks',
        'queue' => 'Fila',
        'documentation' => 'Documentação',
    ],

    'kpis' => [
        'total' => 'Total de webhooks enviados',
        'successful' => 'Com sucesso',
        'failed' => 'Falhados',
        'pending' => 'Pendentes',
        'retry_rate' => 'Taxa de repetição',
    ],

    // Date patterns are translated, not just their month names: the ORDER differs by
    // locale (English leads with the month, Portuguese with the day).
    'formats' => [
        'hour_bucket' => 'j M H:00',
    ],

    'api' => [
        'unsupported_window' => 'O intervalo de métricas pedido não é suportado. Intervalos suportados: :windows.',
        'invalid_window' => 'O intervalo selecionado é inválido. Intervalos suportados: :windows.',
    ],

    'activity' => [
        'title' => 'Atividade por hora',
        'delivered' => 'Entregues',
        'pending' => 'Pendentes',
        'failed' => 'Falhadas',
        'bar_title' => ':hour — :total no total',
    ],

    'latency' => [
        'title' => 'Latência (ms)',
        'p95_trend' => 'Tendência P95',
    ],

    'top_events' => [
        'title' => 'Eventos mais frequentes',
    ],

    'recent' => [
        'title' => 'Fila recente',
    ],

    'setup' => [
        'title' => 'Endpoints',
        'total' => 'Total',
        'active' => 'Ativos',
        'disabled' => 'Desativados',
    ],

    'table' => [
        'event' => 'Evento',
        'status' => 'Estado',
        'attempt' => 'Tentativa',
        'code' => 'Código',
        'duration' => 'Duração',
        'when' => 'Quando',
        'actions' => 'Ações',
        'replay' => 'Reenviar',
    ],

    'filters' => [
        'status' => 'Estado',
        'all_statuses' => 'Todos os estados',
        'event_type' => 'Tipo de evento',
        'event_type_placeholder' => 'Filtrar por tipo de evento',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. Lowercase, as
    // in the original design.
    'status' => [
        'pending' => 'pendente',
        'succeeded' => 'bem-sucedida',
        'failed' => 'falhada',
        'exhausted' => 'esgotada',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'Pendente',
        'succeeded' => 'Bem-sucedida',
        'failed' => 'Falhada',
        'exhausted' => 'Esgotada',
    ],

    'drawer' => [
        'close' => 'Fechar',
        'attempt' => 'Tentativa :number',
        'http' => 'HTTP :code',
        'queued' => 'Em fila',
        'delivered' => 'Entregue',
        'payload' => 'Payload',
        'replay' => 'Reenviar entrega',
    ],

    'empty' => [
        'no_activity' => [
            'title' => 'Ainda sem atividade',
            'description' => 'As entregas neste intervalo aparecem aqui repartidas por hora.',
        ],
        'no_events' => [
            'title' => 'Ainda sem eventos',
            'description' => 'Aqui os teus tipos de evento mais frequentes são ordenados por número.',
        ],
        'no_deliveries' => [
            'title' => 'Ainda sem entregas',
            'description' => 'As entregas vão aparecendo aqui à medida que os teus eventos são enviados.',
        ],
        'no_deliveries_found' => [
            'title' => 'Nenhuma entrega encontrada',
            'description' => 'Nenhuma entrega corresponde aos filtros atuais. Remove um filtro para ver mais.',
        ],
        'no_endpoints' => [
            'title' => 'Nenhum endpoint registado',
            'description' => 'Regista um endpoint de webhook para começar a receber entregas.',
        ],
    ],

    'docs' => [
        'title' => 'Documentação',
        'body' => 'Regista endpoints, assina cada entrega com o esquema Standard Webhooks e reenvia qualquer entrega a partir deste dashboard. Consulta o README do pacote para a referência de configuração completa e o catálogo de eventos.',
    ],

    'toast' => [
        'redelivery_queued' => 'Reenvio colocado em fila.',
        'endpoint_disabled' => 'Este endpoint está desativado. Reativa-o antes de lhe reenviar uma entrega.',
    ],

    // Strings a reader never sees but a screen reader always announces. An
    // untranslated accessible name is an untranslated interface, so they live here
    // with the visible copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Ir para o conteúdo do dashboard',
        'time_window' => 'Intervalo de tempo',
        'sections' => 'Secções do dashboard',
        'retry_rate' => 'Taxa de repetição',
        'deliveries_per_hour' => 'Entregas por hora',
        'hour_summary' => ':hour: :total no total, :delivered entregues, :pending pendentes, :failed falhadas',
        'latency_trend' => 'Tendência da latência P95 por hora',
        'recent_deliveries_table' => 'Entregas de webhook recentes',
        'deliveries_table' => 'Entregas de webhook',
        'replay_delivery' => 'Reenviar a entrega :event',
        'view_delivery' => 'Ver os detalhes da entrega :event',
        'delivery_details' => 'Detalhes da entrega',
        'close_details' => 'Fechar os detalhes',
        'loading_kpis' => 'A carregar as métricas principais',
        'loading_chart' => 'A carregar o gráfico de atividade',
        'loading_panel' => 'A carregar o painel',
        'loading_deliveries' => 'A carregar as entregas',
    ],
];
