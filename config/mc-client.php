<?php

return [
    // Mission Control hub. All three empty → every Mc:: call is a silent no-op.
    'url' => env('MC_URL'),
    'token' => env('MC_TOKEN'),
    'slug' => env('MC_SLUG'),

    // Counters/pushes only run in production by default (same convention as loggio).
    'production_only' => true,

    // Day boundary for counters.
    'timezone' => 'Europe/Moscow',

    // Daily push re-sends every day since the last successful push plus this many
    // trailing days (re-push is harmless: the hub dedupes on read by latest
    // snapshot). First ever push sends full history.
    'push_tail_days' => 3,

    // Ceiling for that catch-up after a long hub outage; older days are skipped
    // with a warning in the log.
    'push_max_catchup_days' => 90,

    // Seconds. Heartbeats retry once on connection failures and 5xx.
    'connect_timeout' => 2,
    'http_timeout' => 3,

    // Min seconds between reports of the same error fingerprint.
    'error_throttle_seconds' => 60,
];
