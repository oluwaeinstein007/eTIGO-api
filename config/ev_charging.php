<?php

return [
    'reservation_fee' => (float) env('EV_RESERVATION_FEE', 500.00),
    'reservation_expiry_minutes' => (int) env('EV_RESERVATION_EXPIRY', 30),
    // Cancelling within this many minutes of a stall being held refunds the fee.
    'free_cancellation_minutes' => (int) env('EV_RESERVATION_FREE_CANCEL_MINUTES', 2),
    'max_queue_size' => (int) env('EV_MAX_QUEUE_SIZE', 10),
    'imminent_departure_minutes' => (int) env('EV_IMMINENT_DEPARTURE', 15),
    'retry_delay_minutes' => (int) env('EV_RETRY_DELAY', 15),
    'max_active_reservations_per_driver' => (int) env('EV_MAX_ACTIVE_RESERVATIONS', 1),

    // Drivers still paying off an E-tiGo car on a hire-purchase agreement
    // reserve for free (client rule, 28 Oct 2026). The second switch decides
    // whether a paused agreement still counts as paying (engineering pick).
    'hire_purchase_fee_waiver' => (bool) env('EV_HIRE_PURCHASE_FEE_WAIVER', true),
    'waive_fee_while_agreement_paused' => (bool) env('EV_WAIVE_FEE_WHILE_AGREEMENT_PAUSED', true),
];
