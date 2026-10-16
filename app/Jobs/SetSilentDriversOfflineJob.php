<?php

namespace App\Jobs;

use App\Enums\RideStatus;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Notifications\DriverSetOfflineNotification;
use App\Services\DriverLocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sets offline the drivers whose app has stopped sending their location.
 *
 * Matching finds drivers by their reported location, so an "online" driver
 * whose app went quiet (closed, killed by the phone, no signal) is never
 * offered a ride, yet looks available to them and to operations. After
 * matching.silent_driver_offline_minutes without a location such a driver is
 * set offline and told to go online again, which restarts the app's location
 * updates. Drivers on a trip are left alone.
 */
class SetSilentDriversOfflineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(DriverLocationService $locationService): void
    {
        $cutoff = now()->subMinutes(config('matching.silent_driver_offline_minutes'));
        $setOffline = 0;

        Driver::query()
            ->where('is_online', true)
            // Going online updates the driver, so this gives a driver who has
            // just gone online time to send a first location.
            ->where('updated_at', '<=', $cutoff)
            ->whereDoesntHave('user.driverRides', fn ($query) => $query->whereIn('status', [
                RideStatus::Matched,
                RideStatus::DriverEnRoute,
                RideStatus::DriverArrived,
                RideStatus::InProgress,
            ]))
            ->with('user')
            ->lazyById()
            ->each(function (Driver $driver) use ($locationService, $cutoff, &$setOffline) {
                $lastSeenAt = $locationService->lastSeenAt($driver->id);

                if ($lastSeenAt !== null && $lastSeenAt->greaterThan($cutoff)) {
                    return;
                }

                // The driver may have gone offline, or online again, since the query.
                $updated = Driver::whereKey($driver->id)
                    ->where('is_online', true)
                    ->where('updated_at', '<=', $cutoff)
                    ->update(['is_online' => false]);

                if ($updated === 0) {
                    return;
                }

                $locationService->removeDriver($driver->id, $driver->user_id);

                AuditLog::record($driver, 'driver_went_offline', null, ['is_online' => true], [
                    'is_online' => false,
                    'reason' => 'no_location',
                    'last_location_at' => $lastSeenAt?->toIso8601String(),
                ]);

                $driver->user->notify(new DriverSetOfflineNotification);
                $setOffline++;
            });

        if ($setOffline > 0) {
            Log::info("Set {$setOffline} driver(s) offline: their app stopped sending a location.");
        }
    }
}
