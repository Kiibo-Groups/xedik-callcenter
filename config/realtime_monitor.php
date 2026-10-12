<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('REALTIME_MONITOR_ENABLED', false),
    'shared_secret' => env('REALTIME_MONITOR_SHARED_SECRET'),
    'timestamp_tolerance' => (int) env('REALTIME_MONITOR_TIMESTAMP_TOLERANCE', 60),
    'stale_seconds' => (int) env('REALTIME_MONITOR_STALE_SECONDS', 15),
    'offline_seconds' => (int) env('REALTIME_MONITOR_OFFLINE_SECONDS', 30),
    'max_payload_bytes' => (int) env('REALTIME_MONITOR_MAX_PAYLOAD_BYTES', 262144),
    'supported_schema_versions' => ['1.0'],
];
