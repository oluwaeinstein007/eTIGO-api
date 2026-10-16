<?php

/*
|--------------------------------------------------------------------------
| Scheduled Rides (Phase 2)
|--------------------------------------------------------------------------
|
| Defaults for rides booked ahead of time. Operations can override every
| value at runtime with PUT /api/v1/admin/settings/scheduled-rides; read them
| through AppSetting::scheduledRides(), not config(), so overrides apply.
|
*/

return [

    // Turn new scheduled bookings on or off. Bookings already made still
    // dispatch when this is off.
    'enabled' => (bool) env('SCHEDULED_RIDES_ENABLED', true),

    // The pickup must be at least this many minutes after booking.
    'min_lead_minutes' => (int) env('SCHEDULED_RIDES_MIN_LEAD_MINUTES', 30),

    // The pickup can be at most this many days after booking. The quote is
    // locked at booking, so a short horizon keeps it close to current pricing.
    'max_advance_days' => (int) env('SCHEDULED_RIDES_MAX_ADVANCE_DAYS', 7),

    // Matching starts this many minutes before the pickup time, so a driver
    // can reach the pickup on time.
    'dispatch_lead_minutes' => (int) env('SCHEDULED_RIDES_DISPATCH_LEAD_MINUTES', 15),

    // Reminder push this many minutes before pickup (0 turns it off). It must
    // come before dispatch so a wallet passenger still has time to top up.
    'reminder_minutes' => (int) env('SCHEDULED_RIDES_REMINDER_MINUTES', 60),

    // Matching keeps searching until this many minutes after the pickup time
    // before giving up with no_driver_found. Also the latest a ride the
    // dispatcher missed (e.g. scheduler outage) can still be dispatched.
    'matching_grace_minutes' => (int) env('SCHEDULED_RIDES_MATCHING_GRACE_MINUTES', 10),

    // Delay between automatic matching waves for a scheduled ride. Scheduled
    // rides keep retrying until the grace period ends instead of stopping
    // after max_auto_retries.
    'retry_wave_delay_seconds' => (int) env('SCHEDULED_RIDES_RETRY_WAVE_DELAY_SECONDS', 30),

    // Upcoming (not yet dispatched) bookings a passenger may hold at once.
    'max_upcoming_per_passenger' => (int) env('SCHEDULED_RIDES_MAX_UPCOMING', 5),

    // Two bookings by the same passenger must be at least this far apart.
    'min_minutes_between_bookings' => (int) env('SCHEDULED_RIDES_MIN_GAP_MINUTES', 30),

];
