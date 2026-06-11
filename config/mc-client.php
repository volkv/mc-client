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

    // Daily push re-sends this many trailing days (re-push is harmless: the hub
    // dedupes on read by latest snapshot). First ever push sends full history.
    'push_tail_days' => 3,

    'http_timeout' => 3,

    // Min seconds between reports of the same error fingerprint.
    'error_throttle_seconds' => 60,
];
