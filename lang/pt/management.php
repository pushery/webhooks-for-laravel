<?php

declare(strict_types=1);

// Copy for the publishable management stubs (the neutral views and their WireKit
// twins). Both variants render the same screens, so they read the same keys — a
// label fixed here stays fixed in both, and a host that restyles one variant keeps
// the wording of the other.
return [
    'form' => [
        'name_label' => 'Nome',
        'url_label' => 'URL do endpoint',
        // An example URL, not prose, but it reaches the reader as a placeholder, so a
        // locale can point it at a domain its audience recognizes.
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_legend' => 'Tipos de evento',
        // The file path travels as a placeholder so a locale is free to put it wherever
        // its grammar wants it.
        'event_types_empty' => 'Configura os tipos de evento em :file.',
        'submit' => 'Registar endpoint',
    ],

    'secret' => [
        'heading' => 'Chave de assinatura (mostrada uma única vez — guarda-a agora)',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'events' => 'Eventos',
        'status' => 'Estado',
        'event' => 'Evento',
        'attempt' => 'Tentativa',
        'code' => 'Código',
        'when' => 'Quando',
        // The actions column shows no visible header, but a column still needs an
        // accessible name — it is read out, so it is translated.
        'actions' => 'Ações',
    ],

    'subscription' => [
        'active' => 'Ativo',
        'disabled' => 'Desativado',
        'enable' => 'Ativar',
        'disable' => 'Desativar',
        'delete' => 'Eliminar',
    ],

    // Deleting an endpoint is irreversible and stops a live integration, so both stubs
    // confirm it first — the WireKit variant through an alert-dialog, the neutral one
    // through the browser confirm.
    'delete_dialog' => [
        'title' => 'Eliminar este endpoint?',
        'description' => 'O endpoint deixa imediatamente de receber webhooks e a sua chave de assinatura é destruída. Esta ação não pode ser anulada.',
        'confirm' => 'Eliminar endpoint',
    ],

    'actions' => [
        'cancel' => 'Cancelar',
    ],

    'empty' => [
        'no_subscriptions' => [
            'title' => 'Ainda sem endpoints',
            'description' => 'Regista o teu primeiro endpoint acima para começar a entregar webhooks.',
        ],
        'no_deliveries' => [
            'title' => 'Nenhuma entrega encontrada',
            'description' => 'As entregas aparecem aqui à medida que os teus eventos são enviados. Remove um filtro para ver mais.',
        ],
    ],

    'deliveries' => [
        'redeliver' => 'Reenviar',
        'ping' => 'Testar',
    ],

    'filters' => [
        // The filter controls hide their labels visually, so these strings reach
        // sighted readers only through assistive technology — they are translated for
        // exactly the same reason a visible label is.
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

    'messages' => [
        // Shown when a replay is asked for an endpoint that is switched off — by its
        // tenant, or by the circuit breaker after too many failures.
        'endpoint_disabled' => 'Este endpoint está desativado. Reativa-o antes de lhe reenviar uma entrega.',
    ],

    'validation' => [
        'url' => [
            // What the reader gets when the SSRF guard refuses the destination. The
            // guard's own message stays untranslated: it is an operator diagnostic for
            // the log, and it would tell a stranger which hosts resolve where.
            'blocked' => 'Este URL não pode ser usado como endpoint. Usa um URL https acessível publicamente.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface.
    'a11y' => [
        'delete_subscription' => 'Eliminar o endpoint :url',
    ],
];
