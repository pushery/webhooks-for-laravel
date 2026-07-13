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
        'include_hint' => 'Só estes campos são mantidos. Deixa vazio para os manter todos.',
        'add_include' => 'Adicionar campo a incluir',
        'exclude_label' => 'Excluir campos',
        'exclude_hint' => 'Estes campos são removidos do corpo.',
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

    'empty' => [
        'no_endpoints' => [
            'title' => 'Ainda sem endpoints',
            'description' => 'Regista o teu primeiro endpoint de webhook para começar a receber eventos.',
        ],
        'no_endpoints_health' => [
            'title' => 'Ainda sem endpoints',
            'description' => 'Regista um endpoint de webhook para começar a acompanhar a sua saúde aqui.',
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

    'toast' => [
        'endpoint_registered' => 'Endpoint registado.',
        'endpoint_updated' => 'Endpoint atualizado.',
        'endpoint_deleted' => 'Endpoint eliminado.',
        'secret_rotated' => 'Chave de assinatura rodada.',
        'health_recomputed' => 'Saúde do endpoint recalculada.',
        'health_recomputed_all' => 'Saúde recalculada para todos os endpoints.',
        'transform_saved' => 'Transformação de payload guardada.',
    ],

    // The form's own validation copy, passed to the validator as custom messages and
    // attribute names, so a refused save speaks the reader's language rather than the
    // framework's default English lines.
    'validation' => [
        'name' => [
            'max' => 'O nome não pode ter mais de :max carateres.',
        ],
        'url' => [
            'required' => 'O URL do endpoint é obrigatório.',
            'url' => 'Introduz um URL de endpoint válido.',
            // The SSRF guard's own message names the resolved host and address, which is
            // a probe oracle in a tenant-facing form. The reason the URL was refused is
            // always the same one a tenant can act on: it must be public and https.
            'blocked' => 'Este URL não pode ser usado como endpoint. Usa um URL https acessível publicamente.',
        ],
        'event_types' => [
            'required' => 'Seleciona pelo menos um tipo de evento.',
            'min' => 'Seleciona pelo menos um tipo de evento.',
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
        'toggle_active' => 'Alternar o estado ativo de :url',
        'reveal_secret' => 'Mostrar a chave de assinatura de :url',
        'edit_endpoint' => 'Editar o endpoint :url',
        'edit_transform' => 'Editar a transformação de payload de :url',
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
    ],
];
