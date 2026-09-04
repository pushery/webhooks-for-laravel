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
        'title' => 'Num relance',
        'total' => 'Total de webhooks enviados',
        'successful' => 'Com sucesso',
        'failed' => 'Falhados',
        'pending' => 'Pendentes',
        'retry_rate' => 'Taxa de repetição',
    ],

    // Date patterns are translated, not just their month names: the ORDER differs by
    // locale (English leads with the month, Portuguese with the day).
    'formats' => [
        'hour_bucket' => 'j M H:i',
        'absolute' => 'LLL z',

        // 'precise' is a second pattern because 'absolute' cannot separate two rows and the
        // accessible names need it to. lang/en/dashboard.php states the case in full, including
        // what it still does not cover.
        'precise' => 'LL LTS z',
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
    // Shown when the rollup the counts come from has fallen behind the rows it summarizes --
    // twice the configured refresh cadence or more, so a run merely in progress never triggers it.
    'rollup_stale' => 'As contagens de entrega desta página estão :minutes minutos atrasadas. Vêm de um rollup que o `webhooks:refresh-metrics` avança; as latências ao lado são em direto, por isso os dois divergem até esse comando correr de novo.',

    // These agree with a noun that is not in the string, and four of the five used to get it wrong.
    // They label one column of one table, and the thing they label is a delivery (a entrega),
    // feminine in this language. Four were masculine and the fifth feminine, so the column read as
    // machine translation on the most-read screen in the package.
    //
    // No guard can check this: agreement is a fact about a word that never appears beside them. The
    // noun is written here so the next translator has it.
    'status' => [
        'pending' => 'pendente',
        'succeeded' => 'bem-sucedida',
        'failed' => 'falhada',
        'exhausted' => 'esgotada',
        'refused' => 'recusada',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'Pendente',
        'succeeded' => 'Bem-sucedida',
        'failed' => 'Falhada',
        'exhausted' => 'Esgotada',
        'refused' => 'Recusada',
    ],

    'drawer' => [
        'close' => 'Fechar',
        'attempt' => 'Tentativa :number',
        'http' => 'HTTP :code',
        'queued' => 'Em fila',
        'delivered' => 'Entregue',
        'payload' => 'Payload',
        'replay' => 'Reenviar entrega',
        // Shown INSTEAD of the values when the payload ability denies the read. It has
        // to say why: a panel that just stops after its heading reads as a defect.
        'payload_redacted' => 'Os valores estão ocultos. A estrutura é mostrada para que possas verificar o formato do corpo sem ler os dados que ele contém.',
        'payload_offloaded' => 'Este corpo era demasiado grande para o registo e foi movido para o disco :disk. O que aparece aqui é o fragmento que o registo guardou, não o corpo entregue.',
        'payload_hidden' => 'O corpo está oculto. Não tens permissão para ver os payloads das entregas.',
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
        'hour_summary' => [
            // Four choice fragments rather than one sentence with four numbers in it: agreement is
            // decided by the number, `trans_choice` takes one count per string, and there are four.
            // lang/en/dashboard.php states the case in full, including why English, German and
            // Dutch carry identical forms on both sides.
            'total' => '{0} :count no total|{1} :count no total|[2,*] :count no total',
            'delivered' => '{0} :count entregues|{1} :count entregue|[2,*] :count entregues',
            'pending' => '{0} :count pendentes|{1} :count pendente|[2,*] :count pendentes',
            'failed' => '{0} :count falhadas|{1} :count falhada|[2,*] :count falhadas',
        ],
        'latency_trend' => 'Tendência da latência P95 por hora',
        'latency_bar' => ':hour: :value ms',
        'recent_deliveries_table' => 'Entregas de webhook recentes',
        'deliveries_table' => 'Entregas de webhook',
        'replay_delivery' => ':label — :event · :endpoint · :at',
        'view_delivery' => 'Ver os detalhes da entrega :event para :endpoint de :at',
        'delivery_details' => 'Detalhes da entrega',
        'close_details' => 'Fechar os detalhes',
        'loading_kpis' => 'A carregar as métricas principais',
        'loading_chart' => 'A carregar o gráfico de atividade',
        'loading_panel' => 'A carregar o painel',
        'loading_deliveries' => 'A carregar as entregas',
    ],
];
