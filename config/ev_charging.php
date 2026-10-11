<?php

return [
    'reservation_fee' => (float) env('EV_RESERVATION_FEE', 500.00),
    'reservation_expiry_minutes' => (int) env('EV_RESERVATION_EXPIRY', 30),
    'max_queue_size' => (int) env('EV_MAX_QUEUE_SIZE', 10),
    'imminent_departure_minutes' => (int) env('EV_IMMINENT_DEPARTURE', 15),
    'retry_delay_minutes' => (int) env('EV_RETRY_DELAY', 15),
    'max_active_reservations_per_driver' => (int) env('EV_MAX_ACTIVE_RESERVATIONS', 1),
];
