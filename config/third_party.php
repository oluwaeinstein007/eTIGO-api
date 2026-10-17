<?php

/*
|--------------------------------------------------------------------------
| Third-Party Bookings (Phase 2)
|--------------------------------------------------------------------------
|
| A passenger (the booker) books and pays for a ride someone else takes (the
| rider). Defaults follow the OQ-18 recommendation (16 Oct 2026, awaiting
| client confirmation). Operations can override every value except
| tracking_url at runtime with PUT /api/v1/admin/settings/third-party; read
| them through AppSetting::thirdParty(), not config(), so overrides apply.
|
*/

return [

    // Turn new third-party bookings on or off. Rides already booked carry on.
    'enabled' => (bool) env('THIRD_PARTY_BOOKINGS_ENABLED', true),

    // Who gets the ride PIN and the live tracking: booker, rider or both.
    // Saved on each ride at booking. Whatever these say, the booker is shown
    // the PIN if the rider's message cannot be delivered.
    'pin_recipient' => env('THIRD_PARTY_PIN_RECIPIENT', 'both'),
    'tracking_recipient' => env('THIRD_PARTY_TRACKING_RECIPIENT', 'both'),

    // The booker pays but is not in the car, so cash and bank transfer
    // (paid to the driver at the end of the trip) are off by default.
    'allow_cash' => (bool) env('THIRD_PARTY_ALLOW_CASH', false),
    'allow_bank_transfer' => (bool) env('THIRD_PARTY_ALLOW_BANK_TRANSFER', false),

    // Third-party rides happening at once per booker. They do not count
    // towards the booker's own one-active-ride limit.
    'max_active_per_booker' => (int) env('THIRD_PARTY_MAX_ACTIVE_PER_BOOKER', 2),

    // Different rider phone numbers one booker can book for in 24 hours, so
    // the feature cannot be used to message strangers.
    'max_rider_numbers_per_day' => (int) env('THIRD_PARTY_MAX_RIDER_NUMBERS_PER_DAY', 10),

    // Also tell the rider when the driver arrives and if the ride is
    // cancelled, after the driver-details message.
    'rider_trip_updates' => (bool) env('THIRD_PARTY_RIDER_TRIP_UPDATES', true),

    // The no-app tracking page sent to the rider. {ride} and {token} are
    // replaced with the ride ID and its share token.
    'tracking_url' => env('THIRD_PARTY_TRACKING_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/track/{ride}/{token}'),

];
