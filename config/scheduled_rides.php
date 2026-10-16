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

    // The pickup can be at most this many days after booking. Set to 3 by
    // the client (16 Oct 2026). The quote is locked at booking, so a short
    // horizon keeps it close to current pricing.
    'max_advance_days' => (int) env('SCHEDULED_RIDES_MAX_ADVANCE_DAYS', 3),

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
    // Set to 3 by the client (16 Oct 2026).
    'max_upcoming_per_passenger' => (int) env('SCHEDULED_RIDES_MAX_UPCOMING', 3),

    // Two bookings by the same passenger must be at least this far apart.
    'min_minutes_between_bookings' => (int) env('SCHEDULED_RIDES_MIN_GAP_MINUTES', 30),

    /*
    | Advance driver reservations (Uber Reserve model): drivers browse upcoming
    | bookings and commit to one; operations can also assign a driver ahead.
    */
    'driver_reservations_enabled' => (bool) env('SCHEDULED_RIDES_DRIVER_RESERVATIONS', true),

    // Upcoming reservations one driver may hold.
    'max_reservations_per_driver' => (int) env('SCHEDULED_RIDES_MAX_RESERVATIONS_PER_DRIVER', 5),

    // A driver's reserved pickups must be at least this far apart.
    'driver_reservation_gap_minutes' => (int) env('SCHEDULED_RIDES_DRIVER_GAP_MINUTES', 60),

    // A driver with a reserved pickup stops getting on-demand offers this
    // long before it, so a trip in between does not make them late.
    'driver_reservation_buffer_minutes' => (int) env('SCHEDULED_RIDES_DRIVER_BUFFER_MINUTES', 60),

    /*
    | Fees, decided by the client on 16 Oct 2026 (Augustus Opara).
    */

    // Added to every scheduled ride's quote, in NGN. Client: no charge for
    // reservations, so 0.
    'reservation_fee' => (float) env('SCHEDULED_RIDES_RESERVATION_FEE', 0),

    // Charged when the passenger cancels a booking a driver has reserved,
    // within late_cancellation_window_minutes of pickup, and paid to that
    // driver in full. Emergency, safety, vehicle-mismatch and driver-no-show
    // cancellations are exempt. What cannot be collected from the wallet or
    // card (including every cash or bank-transfer booking) becomes a balance
    // due the passenger must pay before booking again.
    //
    // How the amount is set:
    //   economy_base_fare — the current base fare of the economy class
    //                       (late_cancellation_fee_vehicle_class) in the
    //                       ride's city. Client decision ("base economy fee").
    //   flat              — late_cancellation_fee, in NGN.
    //   off               — no fee.
    'late_cancellation_fee_basis' => env('SCHEDULED_RIDES_LATE_CANCELLATION_FEE_BASIS', 'economy_base_fare'),
    'late_cancellation_fee_vehicle_class' => env('SCHEDULED_RIDES_LATE_CANCELLATION_FEE_CLASS', 'economy'),
    'late_cancellation_fee' => (float) env('SCHEDULED_RIDES_LATE_CANCELLATION_FEE', 0),
    // Client: 30 minutes before the ride time.
    'late_cancellation_window_minutes' => (int) env('SCHEDULED_RIDES_LATE_CANCELLATION_WINDOW_MINUTES', 30),

];
