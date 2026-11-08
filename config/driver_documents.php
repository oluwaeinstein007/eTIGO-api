<?php

/*
|--------------------------------------------------------------------------
| Driver document renewals
|--------------------------------------------------------------------------
|
| Papers are renewed when they expire, not on a yearly clock, and renewing
| one is a document check, not a new KYC. Engineering recommendation of
| 7 Nov 2026, not yet client-confirmed. Operations can override every value
| at runtime with PUT /api/v1/admin/settings/driver-documents; read them
| through AppSetting::driverDocuments(), not config(), so overrides apply.
|
| Vehicle papers (insurance, roadworthiness, vehicle licence) keep their own
| grace period and block under config/vehicle_maintenance.php; this file
| only adds their reminder emails.
|
*/

return [

    // Reminders (push, in-app and email). Off: nothing is sent; the
    // driving licence block below still applies.
    'reminders_enabled' => (bool) env('DRIVER_DOCUMENTS_REMINDERS_ENABLED', true),

    // Days before the expiry date on which the driver is reminded, plus one
    // reminder on the day itself and one once it has expired. A paper that
    // is approved late only gets the nearest step, never a backlog.
    'reminder_days' => [30, 14, 7, 1],

    // An expired driving licence: days of grace after the expiry date, then
    // the driver cannot go online or get offers until a renewed licence is
    // approved. 0 = blocked from the day after the expiry date. Never ends a
    // trip in progress. Government ID expiry only reminds; it never blocks.
    'licence_grace_days' => (int) env('DRIVER_DOCUMENTS_LICENCE_GRACE_DAYS', 0),
    'block_on_expired_licence' => (bool) env('DRIVER_DOCUMENTS_BLOCK_ON_EXPIRED_LICENCE', true),

    // Look a working driver's renewed licence up in FRSC records (QoreID's
    // licence check, one paid lookup per renewal upload) and show operations
    // the result and FRSC's expiry date. It informs the review; it never
    // approves or rejects on its own. Not a new KYC: the driver's KYC status
    // is untouched.
    'frsc_check_on_renewal' => (bool) env('DRIVER_DOCUMENTS_FRSC_CHECK_ON_RENEWAL', true),

];
