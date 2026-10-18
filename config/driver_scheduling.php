<?php

/*
|--------------------------------------------------------------------------
| Driver scheduling (Phase 2, OQ-20)
|--------------------------------------------------------------------------
|
| Voluntary availability planning for every driver, and assigned shifts
| only for fleet cars in shift mode (cars two or more drivers share).
| Engineering recommendation of 16 Oct 2026, not yet client-confirmed.
| Operations can override every value at runtime with
| PUT /api/v1/admin/settings/driver-scheduling; read them through
| AppSetting::driverScheduling(), not config(), so overrides apply.
|
*/

return [

    // Shifts on shared fleet cars. Off: shift-mode cars stop enforcing
    // their timetable (drivers holding the car can go online any time).
    'shifts_enabled' => (bool) env('DRIVER_SHIFTS_ENABLED', true),

    // A shift driver can go online this many minutes before the shift starts.
    'shift_online_lead_minutes' => (int) env('DRIVER_SHIFT_ONLINE_LEAD_MINUTES', 15),

    // New ride offers stop this many minutes before the shift ends, so the
    // car is handed back on time. A trip running past the end finishes.
    'shift_offer_cutoff_minutes' => (int) env('DRIVER_SHIFT_OFFER_CUTOFF_MINUTES', 15),

    // Not online this many minutes after the start: the shift is marked
    // missed and operations are alerted. No automatic fine.
    'shift_missed_after_minutes' => (int) env('DRIVER_SHIFT_MISSED_AFTER_MINUTES', 30),

    // Shortest and longest shift, and how far ahead operations can plan.
    'shift_min_minutes' => (int) env('DRIVER_SHIFT_MIN_MINUTES', 60),
    'shift_max_hours' => (int) env('DRIVER_SHIFT_MAX_HOURS', 12),
    'shift_max_advance_days' => (int) env('DRIVER_SHIFT_MAX_ADVANCE_DAYS', 30),

    // Availability plans: how far ahead, how long, and how many upcoming
    // plans one driver can hold.
    'availability_max_advance_days' => (int) env('DRIVER_AVAILABILITY_MAX_ADVANCE_DAYS', 14),
    'availability_min_minutes' => (int) env('DRIVER_AVAILABILITY_MIN_MINUTES', 30),
    'availability_max_hours' => (int) env('DRIVER_AVAILABILITY_MAX_HOURS', 12),
    'availability_max_upcoming' => (int) env('DRIVER_AVAILABILITY_MAX_UPCOMING', 42),

];
