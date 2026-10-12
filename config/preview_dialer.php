<?php

declare(strict_types=1);

return [
    'poll_interval_ms'                => (int) env('PREVIEW_DIALER_POLL_INTERVAL_MS', 5000),
    'accepted_start_timeout_seconds'  => (int) env('PREVIEW_ACCEPTED_START_TIMEOUT_SECONDS', 120),
    'stale_scan_limit'                => (int) env('PREVIEW_STALE_SCAN_LIMIT', 50),
];
