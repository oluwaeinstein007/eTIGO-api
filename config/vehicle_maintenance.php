<?php

/*
|--------------------------------------------------------------------------
| Vehicle maintenance (Phase 2, OQ-19)
|--------------------------------------------------------------------------
|
| General items for every vehicle, plus battery health for electric and
| plug-in hybrid cars, chosen by fuel type. Engineering recommendation of
| 16 Oct 2026, not yet client-confirmed. Operations can override every
| value at runtime with PUT /api/v1/admin/settings/vehicle-maintenance;
| read them through AppSetting::vehicleMaintenance(), not config(), so
| overrides apply.
|
*/

return [

    // Reminders, warnings and the expired-papers block. Off: items are
    // still tracked and shown, nothing is sent or blocked.
    'enabled' => (bool) env('VEHICLE_MAINTENANCE_ENABLED', true),

    // Intervals per item: whichever of km / months comes first. null = not
    // used for that item. Legal papers go by their expiry date instead.
    'intervals' => [
        'service' => ['km' => 5000, 'months' => 3],
        'tyres_brakes' => ['km' => null, 'months' => 6],
        'battery_health' => ['km' => null, 'months' => 12],
    ],

    // Per fuel type overrides of the intervals above, for example
    // ['electric' => ['service' => ['km' => 10000, 'months' => 6]]].
    'fuel_type_intervals' => [],

    // Reminder this many days (or km, for the km limit) before an item is due.
    'due_soon_days' => (int) env('VEHICLE_MAINTENANCE_DUE_SOON_DAYS', 14),
    'due_soon_km' => (int) env('VEHICLE_MAINTENANCE_DUE_SOON_KM', 500),

    // Expired insurance, roadworthiness or vehicle licence: days of grace,
    // then the driver cannot go online until a new paper is approved.
    // Never ends a trip in progress.
    'legal_grace_days' => (int) env('VEHICLE_MAINTENANCE_LEGAL_GRACE_DAYS', 7),
    'block_on_expired_papers' => (bool) env('VEHICLE_MAINTENANCE_BLOCK_ON_EXPIRED_PAPERS', true),

];
