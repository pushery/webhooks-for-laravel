<?php

declare(strict_types=1);

return [
    // The browser window title for every self-service page (the shared layout).
    'title' => 'Webhook endpoints',

    'page' => [
        'heading' => 'Webhook endpoints',
        'intro' => 'Register the endpoints your application should receive webhooks on, choose the events each one listens for, and manage its signing secret.',
        'health_link' => 'Endpoint health',
    ],

    'list' => [
        'heading' => 'Your endpoints',
        'new_endpoint' => 'New endpoint',
        'cap_reached' => 'Endpoint limit reached.',
        'secret' => 'Secret',
        'edit' => 'Edit',
        'transform' => 'Transform',
        'delete' => 'Delete',
        'active' => 'Active',
        'disabled' => 'Disabled',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'health' => 'Health',
        'events' => 'Events',
        'status' => 'Status',
        'score' => 'Score',
        'success_rate' => 'Success rate',
        'p95' => 'p95',
        'sample' => 'Sample',
        'as_of' => 'As of',
        'actions' => 'Actions',
    ],

    // Badge labels for the stored health band. The key is the persisted health_status
    // value and is never translated; only the label a reader sees is.
    'health' => [
        'healthy' => 'Healthy',
        'degraded' => 'Degraded',
        'failing' => 'Failing',
        'unknown' => 'Unknown',
    ],

    'form' => [
        'new_heading' => 'New endpoint',
        'edit_heading' => 'Edit endpoint',
        'name_label' => 'Name',
        'name_hint' => 'An optional label to recognise this endpoint.',
        'url_label' => 'Endpoint URL',
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_label' => 'Event types',
        'no_event_types' => 'No event types are configured for this application yet.',
        'active_label' => 'Active',
        'active_hint' => 'Deliveries are only sent while an endpoint is active.',
        'register' => 'Register endpoint',
        'save' => 'Save changes',
    ],

    'delete_dialog' => [
        'title' => 'Delete this endpoint?',
        'description' => 'This permanently removes the endpoint and stops every delivery to it. This cannot be undone.',
        'confirm' => 'Delete endpoint',
    ],

    'secret' => [
        'heading' => 'Signing secret',
        'hide' => 'Hide',
        'hidden_announcement' => 'Signing secret hidden.',
        'notice' => 'Store this secret now — it is shown only for a short time and cannot be retrieved later. Verify every delivery\'s signature with it.',
        // The whole sentence is one translatable unit so a locale can put the number
        // where its grammar wants it; the countdown re-renders it from this same string
        // on every tick.
        'countdown' => 'This secret hides automatically in :seconds s.',
        'countdown_warning' => 'Signing secret hides in 10 seconds.',
        'copy' => 'Copy',
        'copied' => 'Copied!',
        'previous' => 'Previous secret (still accepted during rotation)',
        'rotate' => 'Rotate secret',
    ],

    'health_page' => [
        'heading' => 'Endpoint health',
        'intro' => 'How each of your endpoints is doing, scored from its recent delivery history. Recompute to refresh a score and see its latest success rate, latency and sample size.',
        'recompute' => 'Recompute',
        'recompute_all' => 'Recompute all',
        'never' => 'Never',
    ],

    'transform' => [
        'heading' => 'Payload transform',
        'versioning_disabled' => 'Payload versioning is currently disabled. You can still edit and save this transform; it will not reshape deliveries until versioning is switched on.',
        'rules' => 'Rules',
        'version_label' => 'Payload version',
        'version_hint' => 'Stamped onto the body as payload_version so a receiver can tell the shape it was sent.',
        'version_none' => 'None',
        'field_name_placeholder' => 'field name',
        'include_label' => 'Include fields',
        'include_hint' => 'Only these fields survive. Leave empty to keep them all.',
        'add_include' => 'Add include field',
        'exclude_label' => 'Exclude fields',
        'exclude_hint' => 'These fields are dropped from the body.',
        'add_exclude' => 'Add exclude field',
        'rename_label' => 'Rename fields',
        'rename_hint' => 'Move a field to a new name.',
        'rename_from_placeholder' => 'from',
        'rename_to_placeholder' => 'to',
        'add_rename' => 'Add rename',
        'rewrap_label' => 'Rewrap key',
        'rewrap_hint' => 'Nest the whole body under a single key. Leave empty to send it unwrapped.',
        'rewrap_placeholder' => 'data',
        'save' => 'Save transform',
        'preview_heading' => 'Live preview',
        'sample_label' => 'Sample payload',
        'sample_hint' => 'Edit this to preview against your own data.',
        'invalid_json' => 'This is not a readable JSON object, so there is nothing to preview. Check for a stray comma or a missing quote.',
        'input' => 'Input',
        'output' => 'Output',
    ],

    'empty' => [
        'no_endpoints' => [
            'title' => 'No endpoints yet',
            'description' => 'Register your first webhook endpoint to start receiving events.',
        ],
        'no_endpoints_health' => [
            'title' => 'No endpoints yet',
            'description' => 'Register a webhook endpoint to start tracking its health here.',
        ],
    ],

    'actions' => [
        'cancel' => 'Cancel',
        'remove' => 'Remove',
        'back_to_endpoints' => 'Back to endpoints',
    ],

    // The cap is announced both as a warning toast and as an error on the URL field, so
    // the tenant reads the same sentence wherever it is refused.
    'limit_reached' => 'You have reached your endpoint limit.',

    'toast' => [
        'endpoint_registered' => 'Endpoint registered.',
        'endpoint_updated' => 'Endpoint updated.',
        'endpoint_deleted' => 'Endpoint deleted.',
        'secret_rotated' => 'Signing secret rotated.',
        'health_recomputed' => 'Endpoint health recomputed.',
        'health_recomputed_all' => 'Endpoint health recomputed for all endpoints.',
        'transform_saved' => 'Payload transform saved.',
    ],

    // The form's own validation copy, passed to the validator as custom messages and
    // attribute names, so a refused save speaks the reader's language rather than the
    // framework's default English lines.
    'validation' => [
        'name' => [
            'max' => 'The name may not be longer than :max characters.',
        ],
        'url' => [
            'required' => 'An endpoint URL is required.',
            'url' => 'Enter a valid endpoint URL.',
            // The SSRF guard's own message names the resolved host and address, which is
            // a probe oracle in a tenant-facing form. The reason the URL was refused is
            // always the same one a tenant can act on: it must be public and https.
            'blocked' => 'This URL cannot be used as an endpoint. Use a publicly reachable https URL.',
        ],
        'event_types' => [
            'required' => 'Select at least one event type.',
            'min' => 'Select at least one event type.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface, so they live here with the visible
    // copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Skip to the page content',
        'loading_endpoints' => 'Loading endpoints',
        'endpoints_table' => 'Your webhook endpoints',
        'health_table' => 'Endpoint health',
        'toggle_active' => 'Toggle active state for :url',
        'reveal_secret' => 'Reveal signing secret for :url',
        'edit_endpoint' => 'Edit endpoint :url',
        'edit_transform' => 'Edit payload transform for :url',
        'delete_endpoint' => 'Delete endpoint :url',
        'recompute_health' => 'Recompute health for :url',
        'include_field' => 'Include field :number',
        'remove_include_field' => 'Remove include field :number',
        'exclude_field' => 'Exclude field :number',
        'remove_exclude_field' => 'Remove exclude field :number',
        'rename_source_field' => 'Rename source field :number',
        'rename_target_field' => 'Rename target field :number',
        'remove_rename_pair' => 'Remove rename pair :number',
        'output_preview' => 'Transformed output preview',
        // A short, stable announcement beside the output pane. The pane itself is not a
        // live region: it is recomputed on every debounced keystroke, and announcing the
        // whole JSON body every 400 ms would make the editor unusable with a screen reader.
        'output_updated' => 'Preview updated.',
    ],
];
