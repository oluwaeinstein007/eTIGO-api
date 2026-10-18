<?php

/*
|--------------------------------------------------------------------------
| Lost & Found (Phase 2)
|--------------------------------------------------------------------------
|
| Items left in the car after a trip. Defaults follow Uber, Bolt and Lyft
| practice (engineering choices of 18 Oct 2026, not yet client-confirmed).
| Operations can override every value at runtime with
| PUT /api/v1/admin/settings/lost-and-found; read them through
| AppSetting::lostAndFound(), not config(), so overrides apply.
|
*/

return [

    // Turn new reports on or off. Reports already filed carry on.
    'enabled' => (bool) env('LOST_AND_FOUND_ENABLED', true),

    // How long after the trip ends an item can be reported, in days.
    'report_window_days' => (int) env('LOST_AND_FOUND_REPORT_WINDOW_DAYS', 30),

    // Open reports one passenger can have at once, against misuse.
    'max_open_reports_per_passenger' => (int) env('LOST_AND_FOUND_MAX_OPEN_REPORTS', 3),

    // Hours the driver has to say whether they have the item before the
    // report goes to support (and the driver is reminded).
    'driver_response_hours' => (int) env('LOST_AND_FOUND_DRIVER_RESPONSE_HOURS', 24),

    // Days after the driver confirms by which the item should be back; after
    // that support follows up.
    'return_deadline_days' => (int) env('LOST_AND_FOUND_RETURN_DEADLINE_DAYS', 7),

    // Hours after the driver marks the item returned before the report
    // closes as resolved if the passenger neither confirms nor objects.
    'auto_resolve_hours' => (int) env('LOST_AND_FOUND_AUTO_RESOLVE_HOURS', 72),

    // Days a "not found" report stays open for the passenger to ask support
    // to look into it before it closes.
    'denied_auto_close_days' => (int) env('LOST_AND_FOUND_DENIED_AUTO_CLOSE_DAYS', 7),

    // Show passenger and driver each other's phone number once the driver
    // has the item, so they can arrange the return. Off = in-app messages only.
    'share_contact_details' => (bool) env('LOST_AND_FOUND_SHARE_CONTACT_DETAILS', true),

    // Fee in NGN the passenger pays when the item is returned, paid to the
    // driver in full for the trip back (Uber and Bolt charge one). 0 = off,
    // the default until the client decides on an amount.
    'return_fee' => (float) env('LOST_AND_FOUND_RETURN_FEE', 0),

];
