<?php

return [
    'check_in_delay_seconds' => (int) env('SOS_CHECK_IN_DELAY', 5),
    'escalation_timeout_seconds' => (int) env('SOS_ESCALATION_TIMEOUT', 30),
    'telemetry_encryption' => env('SOS_TELEMETRY_ENCRYPTION', true),
    'max_active_incidents_per_ride' => (int) env('SOS_MAX_ACTIVE_PER_RIDE', 1),
];
