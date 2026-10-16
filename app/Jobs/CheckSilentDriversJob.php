<?php

namespace App\Jobs;

use App\Enums\RideStatus;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Ride;
use App\Notifications\DriverLocationMissingNotification;
use App\Notifications\DriverSetOfflineNotification;
use App\Services\DriverLocationService;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Deals with online drivers whose app has stopped sending their location
 * (closed, killed by the phone, no signal, location switched off).
 *
 * Matching finds drivers by their reported location, so such a driver gets
 * no ride requests, yet looks available to them, to operations (monitoring,
 * manual assignment) and to supply counts. A push reaches the phone even
 * when the app is not running, so the server warns first and acts later:
 *
 * - after matching.silent_driver_warning_minutes the driver is told once per
 *   silent spell that we cannot see them (on a trip: that their passenger
 *   cannot see them);
 * - after matching.silent_driver_offline_minutes a driver who is not on a
 *   trip is set offline and told so.
 *
 * A driver on a trip is never set offline.
 */
class CheckSilentDriversJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ON_TRIP_STATUSES = [
        RideStatus::Matched,
        RideStatus::DriverEnRoute,
        RideStatus::DriverArrived,
        RideStatus::InProgress,
    ];

    public function handle(DriverLocationService $locationService): void
    {
        $now = now();
        $warnAfter = (int) config('matching.silent_driver_warning_minutes');
        $offlineAfter = max((int) config('matching.silent_driver_offline_minutes'), $warnAfter);
        $warnCutoff = $now->copy()->subMinutes($warnAfter);
        $offlineCutoff = $now->copy()->subMinutes($offlineAfter);

        $onTrip = Ride::whereIn('status', self::ON_TRIP_STATUSES)->whereNotNull('driver_id')->pluck('driver_id')->flip();

        $warned = 0;
        $setOffline = 0;

        Driver::query()
            ->where('is_online', true)
            // Gives a driver who has just gone online time to send a first location.
            ->where('online_since', '<=', $warnCutoff)
            ->with('user')
            ->lazyById()
            ->each(function (Driver $driver) use ($locationService, $onTrip, $warnCutoff, $offlineCutoff, $offlineAfter, &$warned, &$setOffline) {
                $lastSeenAt = $locationService->lastSeenAt($driver->id);

                // Silent since the last location, or since going online if
                // no location has come since.
                $silentSince = $lastSeenAt !== null && $lastSeenAt->greaterThan($driver->online_since)
                    ? $lastSeenAt
                    : $driver->online_since;

                if ($silentSince->greaterThan($warnCutoff)) {
                    return;
                }

                $isOnTrip = $onTrip->has($driver->user_id);

                if (! $isOnTrip && $silentSince->lessThanOrEqualTo($offlineCutoff)) {
                    $setOffline += $this->setOffline($driver, $lastSeenAt, $offlineCutoff, $locationService);

                    return;
                }

                // Once per silent spell: a spell is identified by when it began.
                $key = "silent-driver-warned:{$driver->id}:{$silentSince->getTimestamp()}";

                // Kept as long as the last-seen time, so a long silent trip is warned once.
                if (Cache::add($key, true, now()->addDay())) {
                    $driver->user->notify(new DriverLocationMissingNotification($isOnTrip, $offlineAfter));
                    $warned++;
                }
            });

        if ($warned > 0 || $setOffline > 0) {
            Log::info('Checked drivers whose app stopped sending a location', [
                'warned' => $warned,
                'set_offline' => $setOffline,
            ]);
        }
    }

    private function setOffline(Driver $driver, ?CarbonInterface $lastSeenAt, CarbonInterface $offlineCutoff, DriverLocationService $locationService): int
    {
        // The app may have just come back.
        $seenNow = $locationService->lastSeenAt($driver->id);

        if ($seenNow !== null && $seenNow->greaterThan($offlineCutoff)) {
            return 0;
        }

        // Rechecked in the update itself: since the query the driver may have
        // gone offline, gone online again, or been given a ride.
        $updated = Driver::whereKey($driver->id)
            ->where('is_online', true)
            ->where('online_since', '<=', $offlineCutoff)
            ->whereDoesntHave('user.driverRides', fn ($query) => $query->whereIn('status', self::ON_TRIP_STATUSES))
            ->update(['is_online' => false, 'online_since' => null]);

        if ($updated === 0) {
            return 0;
        }

        $locationService->removeDriver($driver->id, $driver->user_id);

        AuditLog::record($driver, 'driver_went_offline', null, ['is_online' => true], [
            'is_online' => false,
            'reason' => 'no_location',
            'last_location_at' => $lastSeenAt?->toIso8601String(),
        ]);

        $driver->user->notify(new DriverSetOfflineNotification);

        return 1;
    }
}
