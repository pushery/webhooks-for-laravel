<?php

declare(strict_types=1);

return [
    // The browser window title for every self-service page (the shared layout).
    'title' => 'Endpoints de webhook',

    'page' => [
        'heading' => 'Endpoints de webhook',
        'intro' => 'Registra los endpoints en los que tu aplicación debe recibir webhooks, elige los eventos que escucha cada uno y gestiona su clave de firma.',
        'health_link' => 'Salud de los endpoints',
    ],

    'list' => [
        'heading' => 'Tus endpoints',
        'new_endpoint' => 'Nuevo endpoint',
        'cap_reached' => 'Límite de endpoints alcanzado.',
        'secret' => 'Clave',
        'edit' => 'Editar',
        'transform' => 'Transformar',
        'delete' => 'Eliminar',
        'active' => 'Activo',
        'disabled' => 'Desactivado',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'health' => 'Salud',
        'events' => 'Eventos',
        'status' => 'Estado',
        'score' => 'Puntuación',
        'success_rate' => 'Tasa de éxito',
        'p95' => 'p95',
        'sample' => 'Muestra',
        'as_of' => 'Actualizado',
        'actions' => 'Acciones',
    ],

    // Badge labels for the stored health band. The key is the persisted health_status
    // value and is never translated; only the label a reader sees is.
    'health' => [
        'healthy' => 'Saludable',
        'degraded' => 'Degradado',
        'failing' => 'Con fallos',
        'unknown' => 'Desconocido',
    ],

    'form' => [
        'new_heading' => 'Nuevo endpoint',
        'edit_heading' => 'Editar endpoint',
        'name_label' => 'Nombre',
        'name_hint' => 'Una etiqueta opcional para reconocer este endpoint.',
        'url_label' => 'URL del endpoint',
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_label' => 'Tipos de evento',
        'no_event_types' => 'Aún no hay tipos de evento configurados para esta aplicación.',
        'active_label' => 'Activo',
        'active_hint' => 'Las entregas solo se envían mientras un endpoint está activo.',
        'register' => 'Registrar endpoint',
        'save' => 'Guardar cambios',
    ],

    'delete_dialog' => [
        'title' => '¿Eliminar este endpoint?',
        'description' => 'Esto elimina el endpoint de forma permanente y detiene todas las entregas hacia él. No se puede deshacer.',
        'confirm' => 'Eliminar endpoint',
    ],

    'secret' => [
        'heading' => 'Clave de firma',
        'hide' => 'Ocultar',
        'hidden_announcement' => 'Clave de firma oculta.',
        'notice' => 'Guarda esta clave ahora — solo se muestra durante un breve momento y no se puede recuperar más tarde. Verifica con ella la firma de cada entrega.',
        // The whole sentence is one translatable unit so a locale can put the number
        // where its grammar wants it; the countdown re-renders it from this same string
        // on every tick.
        'countdown' => 'Esta clave se oculta automáticamente en :seconds s.',
        'countdown_warning' => 'La clave de firma se oculta en 10 segundos.',
        'copy' => 'Copiar',
        'copied' => '¡Copiada!',
        'previous' => 'Clave anterior (aún válida durante la rotación)',
        'rotate' => 'Rotar clave',
    ],

    'health_page' => [
        'heading' => 'Salud de los endpoints',
        'intro' => 'Cómo va cada uno de tus endpoints, evaluado a partir de su historial de entregas reciente. Vuelve a calcular para actualizar una puntuación y ver su última tasa de éxito, latencia y tamaño de muestra.',
        'recompute' => 'Recalcular',
        'recompute_all' => 'Recalcular todo',
        'never' => 'Nunca',
    ],

    'transform' => [
        'heading' => 'Transformación de payload',
        'versioning_disabled' => 'El versionado de payload está desactivado en este momento. Aún puedes editar y guardar esta transformación; no modificará las entregas hasta que se active el versionado.',
        'rules' => 'Reglas',
        'version_label' => 'Versión de payload',
        'version_hint' => 'Se añade al cuerpo como payload_version para que un receptor reconozca la forma con la que se envió.',
        'version_none' => 'Ninguna',
        'field_name_placeholder' => 'nombre del campo',
        'include_label' => 'Incluir campos',
        'include_hint' => 'Solo se conservan estos campos. Déjalo vacío para mantenerlos todos.',
        'add_include' => 'Añadir campo a incluir',
        'exclude_label' => 'Excluir campos',
        'exclude_hint' => 'Estos campos se eliminan del cuerpo.',
        'add_exclude' => 'Añadir campo a excluir',
        'rename_label' => 'Renombrar campos',
        'rename_hint' => 'Mover un campo a un nombre nuevo.',
        'rename_from_placeholder' => 'de',
        'rename_to_placeholder' => 'a',
        'add_rename' => 'Añadir renombrado',
        'rewrap_label' => 'Clave envolvente',
        'rewrap_hint' => 'Anida todo el cuerpo bajo una sola clave. Déjalo vacío para enviarlo sin envolver.',
        'rewrap_placeholder' => 'data',
        'save' => 'Guardar transformación',
        'preview_heading' => 'Vista previa en vivo',
        'sample_label' => 'Payload de ejemplo',
        'sample_hint' => 'Edítalo para ver la vista previa con tus propios datos.',
        'invalid_json' => 'Esto no es un objeto JSON legible, así que no hay nada que previsualizar. Revisa si hay una coma de más o unas comillas que faltan.',
        'input' => 'Entrada',
        'output' => 'Salida',
    ],

    'empty' => [
        'no_endpoints' => [
            'title' => 'Aún no hay endpoints',
            'description' => 'Registra tu primer endpoint de webhook para empezar a recibir eventos.',
        ],
        'no_endpoints_health' => [
            'title' => 'Aún no hay endpoints',
            'description' => 'Registra un endpoint de webhook para empezar a seguir su salud aquí.',
        ],
    ],

    'actions' => [
        'cancel' => 'Cancelar',
        'remove' => 'Quitar',
        'back_to_endpoints' => 'Volver a los endpoints',
    ],

    // The cap is announced both as a warning toast and as an error on the URL field, so
    // the tenant reads the same sentence wherever it is refused.
    'limit_reached' => 'Has alcanzado tu límite de endpoints.',

    'toast' => [
        'endpoint_registered' => 'Endpoint registrado.',
        'endpoint_updated' => 'Endpoint actualizado.',
        'endpoint_deleted' => 'Endpoint eliminado.',
        'secret_rotated' => 'Clave de firma rotada.',
        'health_recomputed' => 'Salud del endpoint recalculada.',
        'health_recomputed_all' => 'Salud recalculada para todos los endpoints.',
        'transform_saved' => 'Transformación de payload guardada.',
    ],

    // The form's own validation copy, passed to the validator as custom messages and
    // attribute names, so a refused save speaks the reader's language rather than the
    // framework's default English lines.
    'validation' => [
        'name' => [
            'max' => 'El nombre no puede tener más de :max caracteres.',
        ],
        'url' => [
            'required' => 'La URL del endpoint es obligatoria.',
            'url' => 'Introduce una URL de endpoint válida.',
            'max' => 'La URL del endpoint no puede tener más de :max caracteres.',
            // The SSRF guard's own message names the resolved host and address, which is
            // a probe oracle in a tenant-facing form. The reason the URL was refused is
            // always the same one a tenant can act on: it must be public and https.
            'blocked' => 'Esta URL no se puede usar como endpoint. Usa una URL https accesible públicamente.',
        ],
        'event_types' => [
            'required' => 'Selecciona al menos un tipo de evento.',
            'min' => 'Selecciona al menos un tipo de evento.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface, so they live here with the visible
    // copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Saltar al contenido de la página',
        'loading_endpoints' => 'Cargando endpoints',
        'endpoints_table' => 'Tus endpoints de webhook',
        'health_table' => 'Salud de los endpoints',
        'toggle_active' => 'Cambiar el estado activo de :url',
        'reveal_secret' => 'Mostrar la clave de firma de :url',
        'edit_endpoint' => 'Editar el endpoint :url',
        'edit_transform' => 'Editar la transformación de payload de :url',
        'delete_endpoint' => 'Eliminar el endpoint :url',
        'recompute_health' => 'Recalcular la salud de :url',
        'include_field' => 'Campo a incluir :number',
        'remove_include_field' => 'Quitar el campo a incluir :number',
        'exclude_field' => 'Campo a excluir :number',
        'remove_exclude_field' => 'Quitar el campo a excluir :number',
        'rename_source_field' => 'Campo de origen del renombrado :number',
        'rename_target_field' => 'Campo de destino del renombrado :number',
        'remove_rename_pair' => 'Quitar el par de renombrado :number',
        'output_preview' => 'Vista previa de la salida transformada',
        // A short, stable announcement beside the output pane. The pane itself is not a
        // live region: it is recomputed on every debounced keystroke, and announcing the
        // whole JSON body every 400 ms would make the editor unusable with a screen reader.
        'output_updated' => 'Vista previa actualizada.',
    ],
];
