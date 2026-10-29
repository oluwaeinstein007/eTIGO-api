<?php

namespace App\Services;

use App\Enums\CancellationReason;
use App\Enums\CityLaunchStatus;
use App\Enums\DriverStatus;
use App\Enums\NotificationType;
use App\Enums\RideStatus;
use App\Exceptions\CityLaunchException;
use App\Jobs\CityLaunchStatusChangedJob;
use App\Jobs\NotifyCityDriversJob;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\CityWaitlistEntry;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves a city between onboarding, live and paused (Phase 2 multi-city,
 * OQ-22). A first launch needs the readiness checklist; resuming a paused
 * city does not. Pausing never touches trips in progress; upcoming bookings
 * go ahead unless the admin cancels them, and then each passenger is told.
 * Closing (back to onboarding, from live or paused) is refused while a trip
 * is running or a booking is open; it clears the manual readiness checks
 * and the launch date, so a relaunch needs fresh ones, and takes the
 * city's online drivers offline. Going live tells the waitlist; drivers
 * hear about launches, pauses and closes.
 */
class CityLaunchService
{
    public const CITY_PAUSED_MESSAGE = 'Your scheduled ride was cancelled because E-tiGo has paused rides in %s.';

    public function __construct(
        private CityReadinessService $readiness,
        private AppCacheService $cache,
        private RideService $rideService,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  'honour'|'cancel'|null  $scheduledRides  For a pause; the setting when null
     * @return array{city: City, previous_status: CityLaunchStatus, scheduled_rides: string|null, scheduled_rides_to_cancel: int, active_rides: int}
     *
     * @throws CityLaunchException
     */
    public function changeStatus(City $city, CityLaunchStatus $to, User $admin, ?string $reason = null, ?string $scheduledRides = null): array
    {
        $result = DB::transaction(function () use ($city, $to, $admin, $reason, $scheduledRides) {
            $city = City::lockForUpdate()->findOrFail($city->id);
            $from = $city->launch_status;

            if ($from === $to) {
                throw new CityLaunchException("{$city->name} is already {$to->label()}.");
            }

            if (! $from->canTransitionTo($to)) {
                throw new CityLaunchException("{$city->name} cannot move from {$from->label()} to {$to->label()}.");
            }

            $closing = $to === CityLaunchStatus::Onboarding;

            if ($closing) {
                $this->assertCanClose($city);
            }

            if ($from === CityLaunchStatus::Onboarding && $to === CityLaunchStatus::Live) {
                $report = $this->readiness->report($city);

                if (! $report['ready']) {
                    throw new CityLaunchException(
                        "{$city->name} is not ready to launch. Complete the readiness checklist first.",
                        $report,
                    );
                }
            }

            $scheduledRides = $to === CityLaunchStatus::Paused
                ? ($scheduledRides ?? AppSetting::cities()['pause_scheduled_rides'])
                : null;

            // Back to onboarding closes the city to bookings entirely. None
            // is open (checked above), so this only catches a booking made in
            // a live city while it was being closed.
            $cancel = $scheduledRides === 'cancel' || $closing;
            $toCancel = $cancel ? $this->readiness->upcomingScheduledRides($city)->count() : 0;

            $cleared = $closing ? array_filter([
                'readiness_confirmations' => $city->readiness_confirmations,
                'launch_date' => $city->launch_date?->toDateString(),
            ]) : [];

            $city->update([
                'launch_status' => $to,
                'paused_at' => $to === CityLaunchStatus::Paused ? now() : null,
                'pause_reason' => $to === CityLaunchStatus::Paused ? $reason : null,
                'pause_scheduled_rides' => $scheduledRides,
                'launch_status_changed_at' => now(),
                'launch_status_changed_by_admin_id' => $admin->id,
                ...($closing ? ['readiness_confirmations' => null, 'launch_date' => null] : []),
            ]);

            $offline = $closing ? $this->takeDriversOffline($city) : collect();

            $event = match (true) {
                $to === CityLaunchStatus::Live && $from === CityLaunchStatus::Onboarding => 'city_launched',
                $to === CityLaunchStatus::Live => 'city_resumed',
                $to === CityLaunchStatus::Paused => 'city_paused',
                default => 'city_returned_to_onboarding',
            };

            AuditLog::record(
                $city,
                $event,
                $admin,
                ['launch_status' => $from->value, ...$cleared],
                array_filter([
                    'launch_status' => $to->value,
                    'reason' => $reason,
                    'scheduled_rides' => $scheduledRides,
                    'scheduled_rides_to_cancel' => $toCancel ?: null,
                    'drivers_taken_offline' => $offline->count() ?: null,
                ], fn ($value) => $value !== null),
            );

            return [
                'city' => $city,
                'previous_status' => $from,
                'scheduled_rides' => $cancel ? 'cancel' : $scheduledRides,
                'scheduled_rides_to_cancel' => $toCancel,
                'active_rides' => Ride::where('city_id', $city->id)
                    ->whereIn('status', RideStatus::activeStatuses())
                    ->count(),
                'cancel' => $cancel,
                'offline' => $offline,
            ];
        });

        $this->cache->invalidateCities();
        $this->removeFromLocationIndex($result['offline']);

        CityLaunchStatusChangedJob::dispatch($result['city']->id, $to->value, $admin->id, $result['cancel']);
        NotifyCityDriversJob::dispatch($result['city']->id, $to->value);

        unset($result['cancel'], $result['offline']);

        return $result;
    }

    /**
     * A city closes only once nothing is left running in it: trips (and
     * dispatched bookings still finding a driver) must finish and upcoming
     * bookings must be cancelled, e.g. by pausing with scheduled_rides
     * cancel. Read under the city's row lock, which dispatch also takes.
     *
     * @throws CityLaunchException
     */
    private function assertCanClose(City $city): void
    {
        $activeRides = Ride::where('city_id', $city->id)
            ->whereIn('status', RideStatus::activeStatuses())
            ->count();
        $bookings = $this->readiness->upcomingScheduledRides($city)->count();

        if ($activeRides === 0 && $bookings === 0) {
            return;
        }

        $open = array_filter([
            match (true) {
                $activeRides === 0 => null,
                $activeRides === 1 => '1 trip is running',
                default => "{$activeRides} trips are running",
            },
            match (true) {
                $bookings === 0 => null,
                $bookings === 1 => '1 upcoming booking is open',
                default => "{$bookings} upcoming bookings are open",
            },
        ]);

        throw new CityLaunchException(
            "{$city->name} cannot be closed while ".implode(' and ', $open).'. Let trips finish and cancel the bookings (pause the city with bookings cancelled), then close it.',
            status: 409,
            context: ['active_rides' => $activeRides, 'upcoming_scheduled_rides' => $bookings],
        );
    }

    /**
     * Online drivers in a closing city have nowhere to drive: no trip is
     * running (checked before), and they cannot go online again until it
     * relaunches.
     *
     * @return Collection<int, Driver>
     */
    private function takeDriversOffline(City $city): Collection
    {
        $drivers = Driver::where('city_id', $city->id)
            ->where('is_online', true)
            ->lockForUpdate()
            ->get(['id', 'user_id']);

        if ($drivers->isNotEmpty()) {
            Driver::whereKey($drivers->modelKeys())->update(['is_online' => false, 'online_since' => null]);
        }

        return $drivers->toBase();
    }

    /**
     * @param  Collection<int, Driver>  $drivers
     */
    private function removeFromLocationIndex(Collection $drivers): void
    {
        if ($drivers->isEmpty()) {
            return;
        }

        try {
            $locations = app(DriverLocationService::class);
            $drivers->each(fn (Driver $driver) => $locations->removeDriver($driver->id, $driver->user_id));
        } catch (\Throwable $e) {
            Log::warning('Failed to remove drivers of a closed city from the live location index', [
                'driver_ids' => $drivers->pluck('id')->all(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The follow-up work of a status change, run on the queue: cancel
     * upcoming bookings if asked and, on going live, tell the waitlist. Both
     * steps are safe to retry. Skipped when the city has moved on since.
     */
    public function applyStatusChange(City $city, CityLaunchStatus $status, ?User $admin, bool $cancelScheduledRides): void
    {
        if ($city->launch_status !== $status) {
            return;
        }

        if ($cancelScheduledRides && $admin !== null) {
            $this->cancelUpcomingBookings($city, $admin);
        }

        if ($status === CityLaunchStatus::Live) {
            $this->notifyWaitlist($city);
        }
    }

    private function cancelUpcomingBookings(City $city, User $admin): void
    {
        $message = sprintf(self::CITY_PAUSED_MESSAGE, $city->name);

        $this->readiness->upcomingScheduledRides($city)
            ->chunkById(100, function ($rides) use ($admin, $message) {
                foreach ($rides as $ride) {
                    try {
                        $this->rideService->cancelRide($ride, $admin, CancellationReason::CityPaused->value, $message);
                    } catch (\Throwable $e) {
                        // The booking may have just been dispatched; the
                        // dispatcher refuses it anyway while the city is closed.
                        Log::warning('Could not cancel a booking in a paused city', [
                            'ride_id' => $ride->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    /**
     * Tell approved drivers in the city it launched, resumed, paused or closed. Run
     * by its own job, never retried, so a failure elsewhere cannot resend it.
     */
    public function notifyDrivers(City $city, CityLaunchStatus $status): void
    {
        if ($city->launch_status !== $status) {
            return;
        }

        [$title, $body] = match ($status) {
            CityLaunchStatus::Live => [
                "E-tiGo is live in {$city->name}",
                'Passengers can now book. Go online to start getting trips.',
            ],
            CityLaunchStatus::Paused => [
                "Rides paused in {$city->name}",
                $city->pause_scheduled_rides === 'cancel'
                    ? 'Passengers cannot book for now and upcoming bookings were cancelled. Trips in progress finish normally.'
                    : 'Passengers cannot book for now. Trips in progress and rides already booked still go ahead.',
            ],
            CityLaunchStatus::Onboarding => [
                "Rides closed in {$city->name}",
                'E-tiGo has stopped rides here for now. You cannot go online until the city reopens; we will tell you when it does. If you were online, you have been taken offline.',
            ],
        };

        Driver::where('city_id', $city->id)
            ->where('status', DriverStatus::Approved)
            ->select(['id', 'user_id'])
            ->chunkById(200, function ($drivers) use ($city, $status, $title, $body) {
                foreach ($drivers as $driver) {
                    $this->notifications->send(
                        userId: $driver->user_id,
                        type: NotificationType::CityStatusChanged,
                        title: $title,
                        body: $body,
                        data: ['city_id' => (string) $city->id, 'launch_status' => $status->value],
                    );
                }
            });
    }

    private function notifyWaitlist(City $city): void
    {
        CityWaitlistEntry::where('city_id', $city->id)
            ->whereNull('notified_at')
            ->chunkById(200, function ($entries) use ($city) {
                foreach ($entries as $entry) {
                    $this->notifications->send(
                        userId: $entry->user_id,
                        type: NotificationType::CityLaunched,
                        title: "E-tiGo is now in {$city->name}",
                        body: 'You can book rides here now. Open the app to get started.',
                        data: ['city_id' => (string) $city->id],
                    );

                    $entry->update(['notified_at' => now()]);
                }
            });
    }
}
