<?php

return [
    'host' => env('TELEMETRY_SERVER_HOST', '0.0.0.0'),
    'port' => (int) env('TELEMETRY_SERVER_PORT', 8081),
    'workers' => (int) env('TELEMETRY_SERVER_WORKERS', 4),
    'rate_limit_ms' => (int) env('TELEMETRY_RATE_LIMIT_MS', 500),
    'location_ttl' => (int) env('TELEMETRY_LOCATION_TTL', 300),
];
