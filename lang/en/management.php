<?php

declare(strict_types=1);

// Copy for the publishable management stubs (the neutral views and their WireKit
// twins). Both variants render the same screens, so they read the same keys — a
// label fixed here stays fixed in both, and a host that restyles one variant keeps
// the wording of the other.
return [
    'form' => [
        'name_label' => 'Name',
        'url_label' => 'Endpoint URL',
        // An example URL, not prose, but it reaches the reader as a placeholder, so a
        // locale can point it at a domain its audience recognizes.
        'url_placeholder' => 'https://example.com/webhooks',
        'event_types_legend' => 'Event types',
        // The file path travels as a placeholder so a locale is free to put it wherever
        // its grammar wants it.
        'event_types_empty' => 'Configure event types in :file.',
        'submit' => 'Register endpoint',
    ],

    'secret' => [
        'heading' => 'Signing secret (shown once — store it now)',
    ],

    'table' => [
        'endpoint' => 'Endpoint',
        'events' => 'Events',
        'status' => 'Status',
        'event' => 'Event',
        'attempt' => 'Attempt',
        'code' => 'Code',
        'when' => 'When',
        // The actions column shows no visible header, but a column still needs an
        // accessible name — it is read out, so it is translated.
        'actions' => 'Actions',
    ],

    'subscription' => [
        'active' => 'Active',
        'disabled' => 'Disabled',
        'enable' => 'Enable',
        'disable' => 'Disable',
        'delete' => 'Delete',
    ],

    // Deleting an endpoint is irreversible and stops a live integration, so both stubs
    // confirm it first — the WireKit variant through an alert-dialog, the neutral one
    // through the browser confirm.
    'delete_dialog' => [
        'title' => 'Delete this endpoint?',
        'description' => 'The endpoint stops receiving webhooks immediately and its signing secret is destroyed. This cannot be undone.',
        'confirm' => 'Delete endpoint',
    ],

    'actions' => [
        'cancel' => 'Cancel',
    ],

    'empty' => [
        'no_subscriptions' => [
            'title' => 'No endpoints yet',
            'description' => 'Register your first endpoint above to start delivering webhooks.',
        ],
        'no_deliveries' => [
            'title' => 'No deliveries found',
            'description' => 'Deliveries appear here as your events are sent. Clear a filter to see more.',
        ],
    ],

    'deliveries' => [
        'redeliver' => 'Redeliver',
        'ping' => 'Ping',
    ],

    'filters' => [
        // The filter controls hide their labels visually, so these strings reach
        // sighted readers only through assistive technology — they are translated for
        // exactly the same reason a visible label is.
        'status' => 'Status',
        'all_statuses' => 'All statuses',
        'event_type' => 'Event type',
        'event_type_placeholder' => 'Filter by event type',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. English keeps
    // the lowercase styling the stubs shipped with.
    'status' => [
        'pending' => 'pending',
        'succeeded' => 'succeeded',
        'failed' => 'failed',
        'exhausted' => 'exhausted',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'Pending',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'exhausted' => 'Exhausted',
    ],

    'messages' => [
        // Shown when a replay is asked for an endpoint that is switched off — by its
        // tenant, or by the circuit breaker after too many failures.
        'endpoint_disabled' => 'This endpoint is disabled. Re-enable it before replaying a delivery to it.',
    ],

    'validation' => [
        'url' => [
            // What the reader gets when the SSRF guard refuses the destination. The
            // guard's own message stays untranslated: it is an operator diagnostic for
            // the log, and it would tell a stranger which hosts resolve where.
            'blocked' => 'This URL cannot be used as an endpoint. Use a publicly reachable https URL.',
        ],
    ],

    // Strings a reader never sees but a screen reader always announces. An untranslated
    // accessible name is an untranslated interface.
    'a11y' => [
        'delete_subscription' => 'Delete endpoint :url',
    ],
];
