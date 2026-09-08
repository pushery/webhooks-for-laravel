<?php

declare(strict_types=1);

// Copy for the publishable management stubs (the neutral views and their WireKit
// twins). Both variants render the same screens, so they read the same keys — a
// label fixed here stays fixed in both, and a host that restyles one variant keeps
// the wording of the other.
return [
    'form' => [
        'error_summary' => '{1} O formulário tem um erro.|[2,*] O formulário tem :count erros.',
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
        // O mesmo formulário, assim que há um endpoint aberto para edição.
        'submit_update' => 'Guardar alterações',
        // Só é oferecido na edição: um registo está ativo por definição.
        'active_label' => 'Ativo',
    ],

    'secret' => [
        'heading' => 'Chave de assinatura (mostrada uma única vez — guarda-a agora)',
        // Uma rotação diz algo que um registo não diz, e quem lê tem de saber: a chave
        // anterior continua a validar até a janela de rotação fechar. É por isso que rodar
        // durante um incidente pode ser feito de imediato.
        'rotated_heading' => 'Nova chave de assinatura (mostrada uma única vez — guarda-a agora). A chave anterior continua a validar até a janela de rotação fechar.',
        // The one-time secret exists in a single response — this console has no reveal
        // window to ask again with — so the copy control is what stands between the reader
        // and a rotation nobody needed.
        'copy' => 'Copiar',
        'copied' => 'Copiada!',
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
        // Health bands, worded exactly as the self-service matrix words them: one
        // package, one vocabulary for the same state.
        'degraded' => 'Degradado',
        'failing' => 'Com falhas',
        // Why an endpoint is off, not just that it is: the breaker and a person write
        // the same two columns, and only the failure streak separates them.
        'auto_disabled' => 'Desativado automaticamente após :count falhas consecutivas',
        'enable' => 'Ativar',
        'disable' => 'Desativar',
        'edit' => 'Editar',
        'rotate' => 'Rodar chave',
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

    // Rodar coloca a chave anterior sob prazo em vez de a invalidar, mas continua a ser
    // uma alteração que cada recetor tem de acompanhar, por isso ambos os stubs a
    // confirmam, tal como a eliminação ao lado.
    // Replaying sends a real HTTP request to a customer's endpoint, so it is never a bare
    // click -- the same reasoning as the rotate and delete confirmations below it.
    'redeliver_dialog' => [
        'title' => 'Enviar esta entrega novamente?',
        'description' => 'O evento é enviado novamente com o seu identificador original. Um destinatário que faz deduplicação irá tratá-lo como um que já viu.',
        'confirm' => 'Enviar novamente',
    ],

    'rotate_dialog' => [
        'title' => 'Rodar esta chave de assinatura?',
        'description' => 'É emitida imediatamente uma nova chave, mostrada uma única vez. A chave atual continua a validar até a janela de rotação fechar — atualiza o recetor até lá.',
        'confirm' => 'Rodar chave',
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
        // The unit, not a column header: the duration hangs off the response code rather
        // than standing in its own cell, because a duration without an answer says nothing.
        // Same string and same reasoning as the portal panel beside it.
        'duration' => ':ms ms',
    ],

    'filters' => [
        // The filter controls hide their labels visually, so these strings reach
        // sighted readers only through assistive technology — they are translated for
        // exactly the same reason a visible label is.
        'status' => 'Estado',
        'all_statuses' => 'Todos os estados',
        'event_type' => 'Tipo de evento',
        'event_type_placeholder' => 'Filtrar por tipo de evento',
        'endpoint' => 'Endpoint',
        'all_endpoints' => 'Todos os endpoints',
        'all_event_types' => 'Todos os tipos de evento',
        'from' => 'De',
        'until' => 'Até',
        'endpoints_truncated' => 'Aqui só são oferecidos os primeiros endpoints. Se faltar o que procuras, filtra por tipo de evento ou adapta este stub.',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. Lowercase, as
    // in the original design.
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

    'messages' => [
        // Shown when a replay is asked for an endpoint that is switched off — by its
        // tenant, or by the circuit breaker after too many failures.
        'endpoint_disabled' => 'Este endpoint está desativado. Reativa-o antes de lhe reenviar uma entrega.',

        // Mostrado quando um ping de teste é recusado por ter esgotado a quota do minuto.
        'ping_throttled' => 'Este endpoint esgotou os seus pings de teste por agora. Tenta novamente dentro de :seconds segundo(s).',
    ],

    'validation' => [
        'event_types' => [
            'string' => 'Um tipo de evento tem de ser um nome, não um número nem uma lista.',
            // An operator registers a GLOBAL endpoint here, so a type nothing publishes
            // costs every tenant's events for it rather than one tenant's.
            'in' => 'Este tipo de evento não é publicado por esta aplicação.',
        ],
        'url' => [
            // The scheme narrowing on the rule ('url:http,https') is what produces this,
            // and without a line here the operator console fell back to the framework's
            // own English 'must be a valid URL' -- for a form whose every other message
            // is translated.
            'url' => 'Introduz um URL de endpoint válido. Tem de começar por http:// ou https://.',
            // What the reader gets when the SSRF guard refuses the destination. The
            // guard's own message stays untranslated: it is an operator diagnostic for
            // the log, and it would tell a stranger which hosts resolve where.
            'blocked' => 'Este URL não pode ser usado como endpoint. Usa um URL https acessível publicamente.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface.
    'a11y' => [
        'subscriptions_table' => 'Os teus endpoints de webhook',
        'delivery_log_table' => 'Registo de entregas',
        'edit_subscription' => 'Editar o endpoint :url',
        'rotate_subscription' => ':label — :url',
        'redeliver_delivery' => ':label — :event · :endpoint · :at',
        'toggle_subscription' => ':label — :url',
        'ping_subscription' => ':label — :url',
        'delete_subscription' => 'Eliminar o endpoint :url',
    ],
];
