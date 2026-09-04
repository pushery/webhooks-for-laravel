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
        'title' => 'At a glance',
        'total' => 'Total Webhooks Sent',
        'successful' => 'Successful',
        'failed' => 'Failed',
        'pending' => 'Pending',
        'retry_rate' => 'Retry Rate',
    ],

    // Date patterns are translated, not just their month names: the order differs by locale, since
    // English leads with the month and German with the day. The `z` on 'absolute' is the whole
    // point of it being a translatable pattern rather than a literal: a delivery timestamp is the
    // one column an operator holds against their own records, and an hour of unexplained offset
    // there is not cosmetic. Without the zone the reader cannot tell which clock they are being
    // shown.
    //
    // 'hour_bucket' ends in `H:i`, not the literal `H:00` it used to. The buckets are whole
    // hours, so the two render identically until the dashboard's display zone has a sub-hour offset
    // (India, Nepal, parts of Australia), where the value really is :30 or :45 and a hardcoded `00`
    // prints a time that never existed. A literal that is true for most readers and silently false
    // for some is worse than a format character.
    'formats' => [
        'hour_bucket' => 'M j H:i',
        'absolute' => 'LLL z',

        // 'precise' exists because 'absolute' cannot separate two rows, and the accessible names
        // need it to. `LLL` carries no seconds in any of the seven shipped locales — measured
        // against the vendored Carbon on two deliveries 37 seconds apart, identical in all seven —
        // and `redeliver()` writes a new row on every replay, same event type, same endpoint. So a
        // tenant pressing Send again twice inside a minute produces two rows whose control names
        // agree in every part, which is the state the names were widened to prevent.
        //
        // It is a second pattern rather than seconds added to 'absolute': on the visible surface a
        // per-second timestamp is noise in a column an operator scans, and this string is read by
        // exactly one reader, one row at a time, where the extra precision is the whole point.
        //
        // It still does not separate two replays in the same second, and that is stated here
        // instead of papered over. Two requests inside one second need two clicks a human cannot make, and the
        // alternative — an opaque id in the name — costs every reader legibility to cover a case no
        // reader reaches.
        'precise' => 'LL LTS z',
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
    // Shown when the rollup the counts come from has fallen behind the rows it summarizes --
    // twice the configured refresh cadence or more, so a run merely in progress never triggers it.
    'rollup_stale' => 'The delivery counts on this page are :minutes minutes behind. They come from a rollup that `webhooks:refresh-metrics` advances; the latency figures beside them are live, so the two disagree until that command runs again.',

    'status' => [
        'pending' => 'pending',
        'succeeded' => 'succeeded',
        'failed' => 'failed',
        'exhausted' => 'exhausted',
        'refused' => 'refused',
    ],

    // The same statuses as filter options, where the surrounding form wants them
    // capitalized.
    'status_options' => [
        'pending' => 'Pending',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'exhausted' => 'Exhausted',
        'refused' => 'Refused',
    ],

    'drawer' => [
        'close' => 'Close',
        'attempt' => 'Attempt :number',
        'http' => 'HTTP :code',
        'queued' => 'Queued',
        'delivered' => 'Delivered',
        'payload' => 'Payload',
        'replay' => 'Replay delivery',
        // Shown INSTEAD of the values when the payload ability denies the read. It has
        // to say why: a panel that just stops after its heading reads as a defect.
        'payload_redacted' => 'Values are hidden. The structure is shown so you can check the shape of the body without reading the data it carries.',
        'payload_offloaded' => 'This body was too large to keep in the log and was moved to the :disk disk. What is shown here is the stub the log kept, not the delivered body.',
        'payload_hidden' => 'The body is hidden. You do not have permission to view delivery payloads.',
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
        'hour_summary' => [
            // Four choice fragments rather than one sentence with four numbers in it. The key here
            // used to be `':hour: :total total, :delivered delivered, …'`, and four of the seven
            // locales froze the adjectives in the plural — so every hour bucket holding exactly one
            // delivery announced "1 livrées", "1 entregados", "1 consegnate", "1 entregues". That
            // is the common bucket, not an edge case: a thirty-day window renders up to 720
            // of them and most are sparse. And these strings exist for one reader only, so the
            // ungrammatical half is the whole of what that reader hears.
            //
            // A placeholder cannot fix it. Agreement is decided by the number, `trans_choice` takes
            // one count per string, and there are four. So the sentence is assembled from four
            // fragments the view joins — the pieces are a list, and a list's order is not grammar,
            // so nothing a translator needs is taken away.
            //
            // English, German and Dutch carry identical forms on both sides on purpose: their
            // participles do not inflect here, and writing the pair anyway keeps every locale the
            // same shape, so a translator adding one is never guessing whether their language needs
            // it.
            'total' => '{0} :count total|{1} :count total|[2,*] :count total',
            'delivered' => '{0} :count delivered|{1} :count delivered|[2,*] :count delivered',
            'pending' => '{0} :count pending|{1} :count pending|[2,*] :count pending',
            'failed' => '{0} :count failed|{1} :count failed|[2,*] :count failed',
        ],
        'latency_trend' => 'Per-hour P95 latency trend',
        'latency_bar' => ':hour: :value ms',
        'recent_deliveries_table' => 'Recent webhook deliveries',
        'deliveries_table' => 'Webhook deliveries',
        'replay_delivery' => ':label — :event · :endpoint · :at',
        'view_delivery' => 'View the :event delivery to :endpoint from :at',
        'delivery_details' => 'Delivery details',
        'close_details' => 'Close details',
        'loading_kpis' => 'Loading key metrics',
        'loading_chart' => 'Loading activity chart',
        'loading_panel' => 'Loading panel',
        'loading_deliveries' => 'Loading deliveries',
    ],
];
