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
        'ping' => 'Test',
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
        'name_hint' => 'An optional label to recognize this endpoint.',
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
        'region_label' => 'Signing secret',
        'shown_announcement' => 'Signing secret shown. It hides automatically.',
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
        // Two lines the board says out loud rather than doing in silence.
        'recompute_throttled' => 'You have recomputed a lot just now. Give it a minute and try again — the scheduled refresh keeps the scores current in the meantime.',
        'endpoints_truncated' => 'Only the first endpoints are shown here. Recompute covers exactly the rows on this board.',
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
        'include_hint' => 'Only these fields survive. Leave empty to keep them all. Top-level names only — a dotted path is not a nested field here.',
        'add_include' => 'Add include field',
        'exclude_label' => 'Exclude fields',
        'exclude_hint' => 'These fields are dropped from the body. Top-level names only — a dotted path is not a nested field here.',
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
        'heading' => 'Recent deliveries',
        'filter_label' => 'Filter by endpoint',
        'all_endpoints' => 'All endpoints',
        'endpoints_truncated' => 'Only the first endpoints are offered in this filter. If the one you want is missing, open it from your endpoint list.',
        'event' => 'Event',
        'outcome' => 'Outcome',
        'response_code' => 'Response',
        'when' => 'When',
        // The paginator's own landmark. Distinct from the table's region name and from
        // the heading above it, or a screen-reader user is offered three landmarks with
        // one name and has to guess which is the pager.
        'pagination_label' => 'Recent deliveries, pages',
        'window_label' => 'Time window',
        'window_days' => 'Last :days days',
        'status_label' => 'Filter by outcome',
        'all_statuses' => 'All outcomes',
        'from' => 'From',
        'until' => 'Until',
        'error' => 'Error',
        'replay' => 'Send again',
        'replay_sr' => ':label — :event · :at',
        'endpoint_disabled' => 'This endpoint is switched off, so nothing can be sent to it.',
        'replay_throttled' => 'You have sent a lot of replays just now. Give it a minute and try again.',
        'status' => [
            'pending' => 'Queued',
            'succeeded' => 'Delivered',
            'failed' => 'Failed, retrying',
            'exhausted' => 'Gave up',
            'refused' => 'Not sent',
        ],
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
        'no_deliveries' => [
            'title' => 'No deliveries yet',
            // Names the retention window, because after it there provably are no
            // rows by design and "nothing yet" would mislead about exactly the
            // question this panel exists to answer.
            'description' => 'Nothing has been sent to your endpoints yet. Deliveries older than the retention window are removed, so an older one may have been here and gone.',
            // The same state with a filter on: the unfiltered sentence is a claim
            // about every endpoint the reader owns, and it is false while one is
            // selected — the others may be busy.
            'filtered' => 'Nothing has been sent to this endpoint yet. Deliveries older than the retention window are removed, so an older one may have been here and gone.',
            // A third state, and each of the three has to be TRUE. The two above are
            // claims about what was SENT; this one is a claim about the filters, which is
            // the only honest thing to say when a reader narrowed by outcome or by date.
            'no_match' => 'No delivery matches the filters you set. Clear one to see more.',
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

    // Contention, not the cap: a concurrent registration of the same tenant's held the
    // lock past the wait. Naming the cap here would be a lie the reader cannot act on.
    'limit_busy' => 'Another registration of yours is still in progress. Please try again.',

    // The allowance, which is not the cap: this one is about SPEED, and it clears on its own.
    'registration_throttled' => 'You are registering endpoints too quickly. Please wait a moment and try again.',

    'toast' => [
        'endpoint_registered' => 'Endpoint registered.',
        'endpoint_updated' => 'Endpoint updated.',
        'endpoint_deleted' => 'Endpoint deleted.',
        'secret_rotated' => 'Signing secret rotated.',
        'health_recomputed' => 'Endpoint health recomputed.',
        'health_recomputed_all' => 'Endpoint health recomputed for all endpoints.',
        'transform_saved' => 'Payload transform saved.',
        'ping_sent' => 'Test event sent.',
        'ping_disabled' => 'This endpoint is switched off, so a test event would not arrive.',
        'ping_throttled' => 'This endpoint has had its test events for now. Try again in :seconds second(s).',
    ],

    // The form's own validation copy, passed to the validator as custom messages and
    // attribute names, so a refused save speaks the reader's language rather than the
    // framework's default English lines.
    'validation' => [
        'nested_field' => 'Top-level field names only — ":field" looks like a nested path, and it would match nothing.',
        'name' => [
            'max' => 'The name may not be longer than :max characters.',
        ],
        'url' => [
            'required' => 'An endpoint URL is required.',
            'url' => 'Enter a valid endpoint URL.',
            'max' => 'The endpoint URL may not be longer than :max characters.',
            // The SSRF guard's own message names the resolved host and address, which is
            // a probe oracle in a tenant-facing form. The reason the URL was refused is
            // always the same one a tenant can act on: it must be public and https.
            'blocked' => 'This URL cannot be used as an endpoint. Use a publicly reachable https URL.',
        ],
        'event_types' => [
            'string' => 'An event type must be a name, not a number or a list.',
            'required' => 'Select at least one event type.',
            'min' => 'Select at least one event type.',
            // A registration for a type the catalog does not declare. Only reachable
            // while the catalog is populated: an empty one places no constraint at all.
            'in' => 'This event type is not one this application publishes.',
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
        'toggle_active' => ':state — toggle active state for :url',
        'reveal_secret' => 'Reveal signing secret for :url',
        'ping_endpoint' => ':label — send a test event to :url',
        'edit_endpoint' => 'Edit endpoint :url',
        'edit_transform' => ':label — edit payload transform for :url',
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
        'deliveries_table' => 'Recent deliveries',
    ],
];
