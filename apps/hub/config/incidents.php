<?php

return [
    'collector_config' => env('NEBULESS_COLLECTOR_CONFIG'),
    'public_url' => env('APP_URL', 'https://localhost'),
    'suspect_seconds' => 45,
    'silent_seconds' => 90,
    'metric_fresh_seconds' => 30,
    'clear_seconds' => 60,
    'cpu_threshold' => 90,
    'memory_threshold' => 90,
    'psi_threshold' => 20,
    'resource_seconds' => 60,
    'psi_seconds' => 30,
    'heartbeat_stale_seconds' => 45,
    'peer_timeout_seconds' => 0.75,
    'receipt_fresh_seconds' => 300,
    'lease_seconds' => 30,
];
