<?php

return [
    'peak_hours' => [
        'morning_start' => env('PROMO_PEAK_MORNING_START', '07:00'),
        'morning_end' => env('PROMO_PEAK_MORNING_END', '09:00'),
        'evening_start' => env('PROMO_PEAK_EVENING_START', '17:00'),
        'evening_end' => env('PROMO_PEAK_EVENING_END', '19:00'),
    ],
];
