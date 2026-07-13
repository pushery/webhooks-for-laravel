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
        'overview' => 'Overview',
        'webhooks' => 'Webhooks',
        'queue' => 'Queue',
        'documentation' => 'Documentation',
    ],

    'kpis' => [
        'total' => 'Total Webhooks Sent',
        'successful' => 'Successful',
        'failed' => 'Failed',
        'pending' => 'Pending',
        'retry_rate' => 'Retry Rate',
    ],

    // Date patterns are translated, not just their month names: the ORDER differs by
    // locale (English leads with the month, German with the day).
    'formats' => [
        'hour_bucket' => 'M j H:00',
    ],

    'api' => [
        'unsupported_window' => 'The requested metrics window is not supported. Supported windows: :windows.',
        'invalid_window' => 'The selected window is invalid. Supported windows: :windows.',
    ],

    'activity' => [
        'title' => 'Hourly activity',
        'delivered' => 'Delivered',
        'pending' => 'Pending',
        'failed' => 'Failed',
        'bar_title' => ':hour — :total total',
    ],

    'latency' => [
        'title' => 'Latency (ms)',
        'p95_trend' => 'P95 trend',
    ],

    'top_events' => [
        'title' => 'Top events',
    ],

    'recent' => [
        'title' => 'Recent queue',
    ],

    'setup' => [
        'title' => 'Endpoints',
        'total' => 'Total',
        'active' => 'Active',
        'disabled' => 'Disabled',
    ],

    'table' => [
        'event' => 'Event',
        'status' => 'Status',
        'attempt' => 'Attempt',
        'code' => 'Code',
        'duration' => 'Duration',
        'when' => 'When',
        'actions' => 'Actions',
        'replay' => 'Replay',
    ],

    'filters' => [
        'status' => 'Status',
        'all_statuses' => 'All statuses',
        'event_type' => 'Event type',
        'event_type_placeholder' => 'Filter by event type',
    ],

    // Badge labels for the stored DeliveryStatus values. The key is the persisted
    // value and is never translated; only the label a reader sees is. English keeps
    // the lowercase styling the badges shipped with.
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

    'drawer' => [
        'close' => 'Close',
        'attempt' => 'Attempt :number',
        'http' => 'HTTP :code',
        'queued' => 'Queued',
        'delivered' => 'Delivered',
        'payload' => 'Payload',
        'replay' => 'Replay delivery',
    ],

    'empty' => [
        'no_activity' => [
            'title' => 'No activity yet',
            'description' => 'Deliveries in this window will appear here as an hourly breakdown.',
        ],
        'no_events' => [
            'title' => 'No events yet',
            'description' => 'Your most frequent event types will be ranked here.',
        ],
        'no_deliveries' => [
            'title' => 'No deliveries yet',
            'description' => 'Deliveries will stream in here as your events are sent.',
        ],
        'no_deliveries_found' => [
            'title' => 'No deliveries found',
            'description' => 'No deliveries match the current filters. Clear a filter to see more.',
        ],
        'no_endpoints' => [
            'title' => 'No endpoints registered',
            'description' => 'Register a webhook endpoint to start receiving deliveries.',
        ],
    ],

    'docs' => [
        'title' => 'Documentation',
        'body' => 'Register endpoints, sign every delivery with the Standard Webhooks scheme, and replay any delivery from this dashboard. See the package README for the full configuration reference and the event catalog.',
    ],

    'toast' => [
        'redelivery_queued' => 'Redelivery queued.',
        'endpoint_disabled' => 'This endpoint is disabled. Re-enable it before replaying a delivery to it.',
    ],

    // Strings a reader never sees but a screen reader always announces. An
    // untranslated accessible name is an untranslated interface, so they live here
    // with the visible copy rather than inline in the views.
    'a11y' => [
        'skip_to_content' => 'Skip to the dashboard content',
        'time_window' => 'Time window',
        'sections' => 'Dashboard sections',
        'retry_rate' => 'Retry rate',
        'deliveries_per_hour' => 'Deliveries per hour',
        'hour_summary' => ':hour: :total total, :delivered delivered, :pending pending, :failed failed',
        'latency_trend' => 'Per-hour P95 latency trend',
        'recent_deliveries_table' => 'Recent webhook deliveries',
        'deliveries_table' => 'Webhook deliveries',
        'replay_delivery' => 'Replay :event delivery',
        'view_delivery' => 'View :event delivery details',
        'delivery_details' => 'Delivery details',
        'close_details' => 'Close details',
        'loading_kpis' => 'Loading key metrics',
        'loading_chart' => 'Loading activity chart',
        'loading_panel' => 'Loading panel',
        'loading_deliveries' => 'Loading deliveries',
    ],
];
