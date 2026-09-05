<?php

declare(strict_types=1);

return [
    // The browser window title for every self-service page (the shared layout).
    'title' => 'Endpoints de webhook',

    'page' => [
        'heading' => 'Endpoints de webhook',
        'intro' => 'Regista os endpoints nos quais a tua aplicação deve receber webhooks, escolhe os eventos que cada um escuta e gere a respetiva chave de assinatura.',
        'health_link' => 'Saúde dos endpoints',
    ],

    'list' => [
        'heading' => 'Os teus endpoints',
        'new_endpoint' => 'Novo endpoint',
        'cap_reached' => 'Limite de endpoints atingido.',
        'ping' => 'Testar',
        'secret' => 'Chave',
        'edit' => 'Editar',
        'transform' => 'Transformar',
        'delete' => 'Eliminar',
        'active' => 'Ativo',
        'disabled' => 'Desativado',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'health' => 'Saúde',
        'events' => 'Eventos',
        'status' => 'Estado',
        'score' => 'Pontuação',
        'success_rate' => 'Taxa de sucesso',
        'p95' => 'p95',
        'sample' => 'Amostra',
        'as_of' => 'Atualizado',
        'actions' => 'Ações',
    ],

    // Badge labels for the stored health band. The key is the persisted health_status
    // value and is never translated; only the label a reader sees is.
    'health' => [
        'healthy' => 'Saudável',
        'degraded' => 'Degradado',
        'failing' => 'Com falhas',
        'unknown' => 'Desconhecido',
    ],

    'form' => [
        'new_heading' => 'Novo endpoint',
        'edit_heading' => 'Editar endpoint',
        'name_label' => 'Nome',
        'name_hint' => 'Uma etiqueta opcional para reconheceres este endpoint.',
        'url_label' => 'URL do endpoint',
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_label' => 'Tipos de evento',
        'no_event_types' => 'Ainda não há tipos de evento configurados para esta aplicação.',
        'active_label' => 'Ativo',
        'active_hint' => 'As entregas só são enviadas enquanto um endpoint estiver ativo.',
        'register' => 'Registar endpoint',
        'save' => 'Guardar alterações',
    ],

    'delete_dialog' => [
        'title' => 'Eliminar este endpoint?',
        'description' => 'Isto remove o endpoint de forma permanente e interrompe todas as entregas para ele. Esta ação não pode ser anulada.',
        'confirm' => 'Eliminar endpoint',
    ],

    'secret' => [
        'region_label' => 'Chave de assinatura',
        'shown_announcement' => 'Chave de assinatura visível. Oculta-se automaticamente.',
        'heading' => 'Chave de assinatura',
        'hide' => 'Ocultar',
        'hidden_announcement' => 'Chave de assinatura ocultada.',
        'notice' => 'Guarda esta chave agora — só é mostrada durante pouco tempo e não pode ser recuperada mais tarde. Verifica com ela a assinatura de cada entrega.',
        // The whole sentence is one translatable unit so a locale can put the number
        // where its grammar wants it; the countdown re-renders it from this same string
        // on every tick.
        'countdown' => 'Esta chave é ocultada automaticamente em :seconds s.',
        'countdown_warning' => 'A chave de assinatura é ocultada em 10 segundos.',
        'copy' => 'Copiar',
        'copied' => 'Copiada!',
        'previous' => 'Chave anterior (ainda aceite durante a rotação)',
        'rotate' => 'Rodar chave',
    ],

    'health_page' => [
        'heading' => 'Saúde dos endpoints',
        'intro' => 'Como está cada um dos teus endpoints, avaliado a partir do seu histórico de entregas recente. Recalcula para atualizar uma pontuação e ver a sua taxa de sucesso, latência e tamanho de amostra mais recentes.',
        'recompute' => 'Recalcular',
        'recompute_all' => 'Recalcular tudo',
        'never' => 'Nunca',
        'recompute_throttled' => 'Recalculou muito há pouco. Aguarde um minuto — entretanto, a atualização agendada mantém as pontuações em dia.',
        'endpoints_truncated' => 'Só são mostrados os primeiros endpoints. O recálculo abrange exactamente as linhas deste quadro.',
    ],

    'transform' => [
        'heading' => 'Transformação de payload',
        'versioning_disabled' => 'O versionamento de payload está desativado de momento. Podes editar e guardar esta transformação à mesma; só passa a remodelar as entregas quando o versionamento for ativado.',
        'rules' => 'Regras',
        'version_label' => 'Versão de payload',
        'version_hint' => 'Escrito no corpo como payload_version para que um recetor reconheça a forma com que os dados foram enviados.',
        'version_none' => 'Nenhuma',
        'field_name_placeholder' => 'nome do campo',
        'include_label' => 'Incluir campos',
        'include_hint' => 'Só estes campos sobrevivem. Deixe vazio para os manter todos. Apenas nomes de primeiro nível — um caminho com pontos não é um campo aninhado aqui.',
        'add_include' => 'Adicionar campo a incluir',
        'exclude_label' => 'Excluir campos',
        'exclude_hint' => 'Estes campos são removidos do corpo. Apenas nomes de primeiro nível — um caminho com pontos não é um campo aninhado aqui.',
        'add_exclude' => 'Adicionar campo a excluir',
        'rename_label' => 'Renomear campos',
        'rename_hint' => 'Mover um campo para um novo nome.',
        'rename_from_placeholder' => 'de',
        'rename_to_placeholder' => 'para',
        'add_rename' => 'Adicionar renomeação',
        'rewrap_label' => 'Chave envolvente',
        'rewrap_hint' => 'Aninha todo o corpo sob uma única chave. Deixa vazio para o enviar sem encapsulamento.',
        'rewrap_placeholder' => 'data',
        'save' => 'Guardar transformação',
        'preview_heading' => 'Pré-visualização em direto',
        'sample_label' => 'Payload de exemplo',
        'sample_hint' => 'Edita isto para pré-visualizares com os teus próprios dados.',
        'invalid_json' => 'Isto não é um objeto JSON legível, por isso não há nada para pré-visualizar. Verifica se há uma vírgula a mais ou umas aspas em falta.',
        'input' => 'Entrada',
        'output' => 'Saída',
    ],

    // The tenant's own delivery log. The status labels are keyed by the STORED
    // DeliveryStatus value, which never changes, and read as outcomes rather than
    // states: a customer asking "did my order go out?" is not asking for an enum.
    // The one date pattern this namespace needs, and it exists for the accessible names rather
    // than for the screen. A replay writes a NEW delivery row — same event type, same endpoint —
    // and `platform.self_service.replays_per_minute` allows ten a minute by default, so two rows
    // that differ only in their second are the ORDINARY outcome of a tenant pressing Send again.
    // `LLL` carries no seconds in any of the seven shipped locales (measured on two deliveries
    // 37 seconds apart, identical in all seven), so the names agreed in every part.
    //
    // Translated rather than a literal for the reason the dashboard's own patterns are: the
    // ORDER differs by locale, and `z` names the clock the reader is being shown.
    'formats' => [
        'precise' => 'LL LTS z',
    ],

    'deliveries' => [
        'heading' => 'Entregas recentes',
        'filter_label' => 'Filtrar por endpoint',
        'all_endpoints' => 'Todos os endpoints',
        'endpoints_truncated' => 'Este filtro só oferece os primeiros endpoints. Se faltar o que procura, abra-o a partir da sua lista de endpoints.',
        'event' => 'Evento',
        'outcome' => 'Resultado',
        'response_code' => 'Resposta',
        // The unit sits in the translation because the ORDER can differ, not because 'ms'
        // does: a locale that writes the unit first has somewhere to say so.
        'duration' => ':ms ms',
        'when' => 'Quando',
        // The paginator's own landmark. Distinct from the table's region name and from
        // the heading above it, or a screen-reader user is offered three landmarks with
        // one name and has to guess which is the pager.
        'pagination_label' => 'Entregas recentes, páginas',
        'window_label' => 'Período',
        'window_days' => 'Últimos :days dias',
        'status_label' => 'Filtrar por resultado',
        'all_statuses' => 'Todos os resultados',
        'from' => 'De',
        'until' => 'Até',
        'error' => 'Erro',
        'replay' => 'Enviar de novo',
        'replay_sr' => ':label — :event · :at',
        'endpoint_disabled' => 'Este endpoint está desligado, por isso não lhe pode ser enviado nada.',
        'replay_throttled' => 'Reenviaste muita coisa agora mesmo. Espera um minuto e tenta de novo.',
        'status' => [
            'pending' => 'Em fila',
            'succeeded' => 'Entregue',
            'failed' => 'Falhou, a repetir',
            'exhausted' => 'Desistiu',
            'refused' => 'Não enviada',
        ],
    ],

    'empty' => [
        'no_endpoints' => [
            'title' => 'Ainda sem endpoints',
            'description' => 'Regista o teu primeiro endpoint de webhook para começar a receber eventos.',
        ],
        'no_endpoints_health' => [
            'title' => 'Ainda sem endpoints',
            'description' => 'Regista um endpoint de webhook para começar a acompanhar a sua saúde aqui.',
        ],
        'no_deliveries' => [
            'title' => 'Ainda sem entregas',
            // Names the retention window, because after it there provably are no
            // rows by design and "nothing yet" would mislead about exactly the
            // question this panel exists to answer.
            'description' => 'Ainda não foi enviado nada para os teus endpoints. As entregas mais antigas do que o período de retenção são removidas, por isso uma anterior pode ter passado por aqui e já ter desaparecido.',
            // The same state with a filter on: the unfiltered sentence is a claim
            // about every endpoint the reader owns, and it is false while one is
            // selected — the others may be busy.
            'filtered' => 'Ainda não foi enviado nada para este endpoint. As entregas mais antigas do que o período de retenção são removidas, por isso uma anterior pode ter passado por aqui e já ter desaparecido.',
            // A third state, and each of the three has to be TRUE. The two above are
            // claims about what was SENT; this one is a claim about the filters, which is
            // the only honest thing to say when a reader narrowed by outcome or by date.
            'no_match' => 'Nenhuma entrega corresponde aos filtros definidos. Remove um para veres mais.',
        ],
    ],

    'actions' => [
        'cancel' => 'Cancelar',
        'remove' => 'Remover',
        'back_to_endpoints' => 'Voltar aos endpoints',
    ],

    // The cap is announced both as a warning toast and as an error on the URL field, so
    // the tenant reads the same sentence wherever it is refused.
    'limit_reached' => 'Atingiste o teu limite de endpoints.',

    // Contenção, não o limite: um registo simultâneo do mesmo tenant reteve o bloqueio.
    'limit_busy' => 'Outro registo teu ainda está em curso. Tenta novamente.',

    // A cadência, não o limite: aqui importa a VELOCIDADE, e liberta-se sozinha.
    'registration_throttled' => 'Estás a registar endpoints demasiado depressa. Espera um momento e tenta novamente.',

    'toast' => [
        'endpoint_registered' => 'Endpoint registado.',
        'endpoint_updated' => 'Endpoint atualizado.',
        'endpoint_deleted' => 'Endpoint eliminado.',
        'secret_rotated' => 'Chave de assinatura rodada.',
        'health_recomputed' => 'Saúde do endpoint recalculada.',
        'health_recomputed_all' => 'Saúde recalculada para todos os endpoints.',
        'transform_saved' => 'Transformação de payload guardada.',
        'ping_sent' => 'Evento de teste enviado.',
        'ping_disabled' => 'Este endpoint está desligado, portanto um evento de teste não chegaria.',
        'ping_throttled' => 'Este endpoint esgotou os seus eventos de teste por agora. Tenta novamente em :seconds segundo(s).',
    ],

    // The form's own validation copy, passed to the validator as custom messages and
    // attribute names, so a refused save speaks the reader's language rather than the
    // framework's default English lines.
    'validation' => [
        'nested_field' => 'Apenas nomes de campo de primeiro nível — ":field" parece um caminho aninhado e não corresponderia a nada.',
        'name' => [
            'max' => 'O nome não pode ter mais de :max carateres.',
        ],
        'url' => [
            'required' => 'O URL do endpoint é obrigatório.',
            'url' => 'Introduz um URL de endpoint válido.',
            'max' => 'O URL do endpoint não pode ter mais de :max caracteres.',
            // The SSRF guard's own message names the resolved host and address, which is
            // a probe oracle in a tenant-facing form. The reason the URL was refused is
            // always the same one a tenant can act on: it must be public and https.
            'blocked' => 'Este URL não pode ser usado como endpoint. Usa um URL https acessível publicamente.',
        ],
        'event_types' => [
            'string' => 'Um tipo de evento tem de ser um nome, não um número nem uma lista.',
            'required' => 'Seleciona pelo menos um tipo de evento.',
            'min' => 'Seleciona pelo menos um tipo de evento.',
            // A registration for a type the catalog does not declare. Only reachable
            // while the catalog is populated: an empty one places no constraint at all.
            'in' => 'Este tipo de evento não é publicado por esta aplicação.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface, so they live here with the visible
    // copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Ir para o conteúdo da página',
        'loading_endpoints' => 'A carregar os endpoints',
        'endpoints_table' => 'Os teus endpoints de webhook',
        'health_table' => 'Saúde dos endpoints',
        'toggle_active' => ':state — alternar o estado ativo de :url',
        'reveal_secret' => 'Mostrar a chave de assinatura de :url',
        'ping_endpoint' => ':label — enviar um evento de teste para :url',
        'edit_endpoint' => 'Editar o endpoint :url',
        'edit_transform' => ':label — editar a transformação de payload de :url',
        'delete_endpoint' => 'Eliminar o endpoint :url',
        'recompute_health' => 'Recalcular a saúde de :url',
        'include_field' => 'Campo a incluir :number',
        'remove_include_field' => 'Remover o campo a incluir :number',
        'exclude_field' => 'Campo a excluir :number',
        'remove_exclude_field' => 'Remover o campo a excluir :number',
        'rename_source_field' => 'Campo de origem da renomeação :number',
        'rename_target_field' => 'Campo de destino da renomeação :number',
        'remove_rename_pair' => 'Remover o par de renomeação :number',
        'output_preview' => 'Pré-visualização da saída transformada',
        // A short, stable announcement beside the output pane. The pane itself is not a
        // live region: it is recomputed on every debounced keystroke, and announcing the
        // whole JSON body every 400 ms would make the editor unusable with a screen reader.
        'output_updated' => 'Pré-visualização atualizada.',
        'deliveries_table' => 'Entregas recentes',
    ],
];
