<?php

namespace App\Services;

use App\Enums\DriverShiftStatus;
use App\Enums\DriverStatus;
use App\Enums\NotificationType;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Advance driver reservations for scheduled rides (Uber Reserve model): a
 * driver commits to a booking ahead of time, or operations assign one. At
 * dispatch the ride goes straight to that driver when they are free and
 * online; otherwise the reservation is released and normal matching runs.
 */
class ScheduledRideReservationService
{
    public function __construct(
        private NotificationService $notificationService,
        private DriverEarningsService $earningsService,
        private DriverLocationService $locationService,
    ) {}

    /**
     * Upcoming, unreserved bookings a driver could take: same city and
     * vehicle class, and matching has not started yet. Soonest first.
     */
    public function availableFor(Driver $driver): Builder
    {
        $leadMinutes = AppSetting::scheduledRides()['dispatch_lead_minutes'];
        $shifts = app(DriverShiftService::class);
        $isShiftDriver = $shifts->isShiftDriver($driver);
        $cutoff = $shifts->settings()['shift_offer_cutoff_minutes'];

        return Ride::query()
            ->where('rides.status', RideStatus::Scheduled)
            ->where('rides.city_id', $driver->city_id)
            // A shared car's driver sees bookings inside their own shifts,
            // in the class of that shift's car.
            ->when(
                $isShiftDriver,
                fn (Builder $q) => $q->whereExists(fn ($s) => $s->selectRaw('1')
                    ->from('driver_shifts')
                    ->join('vehicles', 'vehicles.id', '=', 'driver_shifts.vehicle_id')
                    ->where('driver_shifts.driver_id', $driver->id)
                    ->whereIn('driver_shifts.status', array_map(fn ($status) => $status->value, DriverShiftStatus::workable()))
                    ->whereColumn('driver_shifts.starts_at', '<=', 'scheduled_rides.scheduled_pickup_at')
                    ->whereRaw('driver_shifts.ends_at > scheduled_rides.scheduled_pickup_at + make_interval(mins => ?)', [$cutoff])
                    ->whereColumn('vehicles.vehicle_class_id', 'rides.vehicle_class_id')),
                fn (Builder $q) => $q->where('rides.vehicle_class_id', $driver->vehicle?->vehicle_class_id),
            )
            ->join('scheduled_rides', 'scheduled_rides.ride_id', '=', 'rides.id')
            ->whereNull('scheduled_rides.reserved_driver_id')
            ->whereNull('scheduled_rides.dispatched_at')
            ->whereNull('scheduled_rides.cancelled_at')
            ->where('scheduled_rides.scheduled_pickup_at', '>', now()->addMinutes($leadMinutes))
            ->orderBy('scheduled_rides.scheduled_pickup_at')
            ->select('rides.*');
    }

    /**
     * The driver's upcoming reservations, soonest first.
     */
    public function reservationsOf(User $driverUser): Builder
    {
        return Ride::query()
            ->where('rides.status', RideStatus::Scheduled)
            ->join('scheduled_rides', 'scheduled_rides.ride_id', '=', 'rides.id')
            ->where('scheduled_rides.reserved_driver_id', $driverUser->id)
            ->whereNull('scheduled_rides.dispatched_at')
            ->whereNull('scheduled_rides.cancelled_at')
            ->orderBy('scheduled_rides.scheduled_pickup_at')
            ->select('rides.*');
    }

    /**
     * Why this driver cannot reserve this booking (an empty list when they
     * can). Operations assigning a driver skip only the on/off switch.
     *
     * @return list<string>
     */
    public function reservationErrors(User $driverUser, Ride $ride, bool $byAdmin = false): array
    {
        $settings = AppSetting::scheduledRides();

        if (! $byAdmin && ! $settings['driver_reservations_enabled']) {
            return ['Reserving scheduled rides is not available right now.'];
        }

        $driver = $driverUser->driver()->with('vehicle')->first();

        if ($driver === null || $driver->status !== DriverStatus::Approved) {
            return ['Only approved drivers can reserve scheduled rides.'];
        }

        $scheduled = $ride->scheduledRide;

        if ($ride->status !== RideStatus::Scheduled || $scheduled === null || ! $scheduled->isUpcoming()) {
            return ['This ride is no longer open for reservation.'];
        }

        if ($scheduled->reserved_driver_id !== null && $scheduled->reserved_driver_id !== $driverUser->id) {
            return ['Another driver has already reserved this ride.'];
        }

        $errors = [];

        if (now()->gte($scheduled->dispatchAt($settings['dispatch_lead_minutes']))) {
            $errors[] = 'This ride is about to start looking for a driver and can no longer be reserved.';
        }

        // A shared car's driver can only reserve inside their own shifts,
        // with that shift's car (OQ-20).
        $shifts = app(DriverShiftService::class);
        $vehicle = $driver->vehicle;
        $outsideShifts = false;

        if ($shifts->isShiftDriver($driver)) {
            $vehicle = $shifts->shiftCovering($driver, $scheduled->scheduled_pickup_at)?->vehicle;
            $outsideShifts = $vehicle === null;
        }

        if ($outsideShifts) {
            $errors[] = 'This pickup is outside your shifts.';
        } elseif ($vehicle?->vehicle_class_id !== $ride->vehicle_class_id) {
            $errors[] = 'This ride needs a different vehicle class.';
        }

        if ($vehicle?->isBlocked()) {
            $errors[] = "Your car can't be driven until its papers are renewed: {$vehicle->block_reason}";
        }

        if ($driver->city_id !== $ride->city_id) {
            $errors[] = 'This ride is in another city.';
        }

        if ($this->earningsService->hasExcessiveNegativeBalance($driver->id)) {
            $errors[] = 'Clear your outstanding balance before reserving rides.';
        }

        $others = ScheduledRide::query()
            ->upcoming()
            ->where('reserved_driver_id', $driverUser->id)
            ->where('id', '!=', $scheduled->id)
            ->get();

        if ($others->count() >= $settings['max_reservations_per_driver']) {
            $errors[] = "A driver can hold at most {$settings['max_reservations_per_driver']} upcoming reservations.";
        }

        $gap = $settings['driver_reservation_gap_minutes'];
        if ($others->contains(fn (ScheduledRide $other) => abs($other->scheduled_pickup_at->diffInMinutes($scheduled->scheduled_pickup_at)) < $gap)) {
            $errors[] = "You already have a reserved pickup within {$gap} minutes of this one.";
        }

        return $errors;
    }

    /**
     * Commit a driver to a booking: the driver reserving it, or operations
     * assigning one ($admin).
     *
     * @throws ValidationException
     */
    public function reserve(Ride $ride, User $driverUser, ?User $admin = null): Ride
    {
        $ride = DB::transaction(function () use ($ride, $driverUser, $admin) {
            $ride = Ride::lockForUpdate()->findOrFail($ride->id);
            $scheduled = ScheduledRide::where('ride_id', $ride->id)->lockForUpdate()->first();
            $ride->setRelation('scheduledRide', $scheduled);

            // Operations may move a booking from one driver to another.
            $previousDriverId = $scheduled?->reserved_driver_id;
            if ($admin !== null && $previousDriverId !== null && $previousDriverId !== $driverUser->id) {
                $this->clearReservation($ride, $scheduled, 'admin_released', $admin);
            }

            // The driver's row lock serialises this with ride assignment and
            // an operations city move (reservationErrors reads the driver fresh).
            Driver::where('user_id', $driverUser->id)->lockForUpdate()->first();

            $errors = $this->reservationErrors($driverUser, $ride, $admin !== null);

            if ($errors !== []) {
                throw ValidationException::withMessages([$admin ? 'driver_id' : 'ride' => $errors]);
            }

            if ($scheduled->reserved_driver_id === $driverUser->id) {
                return $ride;
            }

            $scheduled->update([
                'reserved_driver_id' => $driverUser->id,
                'reserved_at' => now(),
                'reserved_by_admin_id' => $admin?->id,
                'reservation_released_reason' => null,
                'driver_reminder_sent_at' => ScheduledRideService::reminderAlreadyCovered($scheduled->scheduled_pickup_at) ? now() : null,
            ]);

            AuditLog::record($ride, $admin ? 'scheduled_ride_driver_assigned' : 'scheduled_ride_reserved', $admin ?? $driverUser, null, [
                'driver_user_id' => $driverUser->id,
            ]);

            return $ride;
        });

        $ride->load('scheduledRide.reservedDriver');
        $this->notifyReserved($ride, $admin !== null);

        return $ride;
    }

    /**
     * Drop a booking's reservation (the booking itself stays). Reasons:
     * driver_released, admin_released, pickup_changed, driver_unavailable,
     * vehicle_blocked (papers expired), shift_changed (outside the driver's shifts),
     * driver_suspended (suspended or deactivated).
     * Must run inside a transaction with the ride and booking locked.
     */
    public function clearReservation(Ride $ride, ScheduledRide $scheduled, string $reason, ?User $actor = null): void
    {
        $driverId = $scheduled->reserved_driver_id;

        if ($driverId === null) {
            return;
        }

        $scheduled->update([
            'reserved_driver_id' => null,
            'reserved_at' => null,
            'reserved_by_admin_id' => null,
            'driver_reminder_sent_at' => null,
            'reservation_released_reason' => $reason,
        ]);

        AuditLog::record($ride, 'scheduled_ride_reservation_released', $actor, ['driver_user_id' => $driverId], ['reason' => $reason]);

        DB::afterCommit(fn () => $this->notifyReleased($ride, $driverId, $reason));
    }

    /**
     * Release the driver's upcoming reservations at once (their car was taken
     * off the road, or they can no longer drive), so the bookings go back to
     * the pool in time. $keep skips the ones that still stand. Each booking is
     * released in its own transaction; returns how many were released.
     *
     * @param  (\Closure(ScheduledRide): bool)|null  $keep
     */
    public function releaseAllFor(string $driverUserId, string $reason, ?\Closure $keep = null): int
    {
        $released = 0;

        ScheduledRide::query()
            ->upcoming()
            ->where('reserved_driver_id', $driverUserId)
            ->get()
            ->reject(fn (ScheduledRide $scheduled) => $keep !== null && $keep($scheduled))
            ->each(function (ScheduledRide $scheduled) use ($driverUserId, $reason, &$released) {
                try {
                    DB::transaction(function () use ($scheduled, $driverUserId, $reason, &$released) {
                        $ride = Ride::lockForUpdate()->find($scheduled->ride_id);
                        $locked = ScheduledRide::lockForUpdate()->find($scheduled->id);

                        if ($ride !== null && $locked !== null && $locked->isUpcoming() && $locked->reserved_driver_id === $driverUserId) {
                            $this->clearReservation($ride, $locked, $reason);
                            $released++;
                        }
                    });
                } catch (\Throwable $e) {
                    Log::warning('Could not release scheduled ride reservation', ['scheduled_ride_id' => $scheduled->id, 'reason' => $reason, 'error' => $e->getMessage()]);
                }
            });

        return $released;
    }

    /**
     * The driver (or operations) gives a reservation up before dispatch.
     *
     * @throws ValidationException
     */
    public function release(Ride $ride, User $actor, bool $byAdmin = false): Ride
    {
        return DB::transaction(function () use ($ride, $actor, $byAdmin) {
            $ride = Ride::lockForUpdate()->findOrFail($ride->id);
            $scheduled = ScheduledRide::where('ride_id', $ride->id)->lockForUpdate()->first();

            if ($ride->status !== RideStatus::Scheduled || $scheduled === null || ! $scheduled->isUpcoming() || $scheduled->reserved_driver_id === null) {
                throw ValidationException::withMessages(['ride' => ['This ride has no reservation to release.']]);
            }

            if (! $byAdmin && $scheduled->reserved_driver_id !== $actor->id) {
                throw ValidationException::withMessages(['ride' => ['You have not reserved this ride.']]);
            }

            $this->clearReservation($ride, $scheduled, $byAdmin ? 'admin_released' : 'driver_released', $actor);

            return $ride->fresh('scheduledRide');
        });
    }

    /**
     * Whether the reserved driver can take the ride right now: approved,
     * online with a recent location, still in the right vehicle class and not
     * blocked by a negative balance. (Being on another trip is checked when
     * the ride is assigned.)
     */
    public function canTakeNow(User $driverUser, Ride $ride): bool
    {
        $driver = $driverUser->driver()->with('vehicle')->first();

        if ($driver === null
            || $driver->status !== DriverStatus::Approved
            || ! $driver->is_online
            || $driver->vehicle?->vehicle_class_id !== $ride->vehicle_class_id
            // Papers expired past the grace period, or a shared car's shift
            // reaching its offer cut-off.
            || ! Driver::whereKey($driver->id)->dispatchable()->exists()
            || $this->earningsService->hasExcessiveNegativeBalance($driver->id)) {
            return false;
        }

        try {
            $location = $this->locationService->getDriverLocation((string) $driver->id);
        } catch (\Throwable $e) {
            // Without the location store, trust the online flag rather than
            // drop every reservation.
            Log::warning('Could not read reserved driver location at dispatch', ['driver_id' => $driver->id, 'error' => $e->getMessage()]);

            return true;
        }

        return $location !== null
            && $location['timestamp'] >= now()->subSeconds(DriverLocationService::STALE_LOCATION_SECONDS)->timestamp;
    }

    private function notifyReserved(Ride $ride, bool $byAdmin): void
    {
        $scheduled = $ride->scheduledRide;
        $driver = $scheduled?->reservedDriver;

        if ($driver === null) {
            return;
        }

        $time = $this->localTime($ride, $scheduled);

        $this->safely(fn () => $this->notificationService->send(
            userId: $ride->passenger_id,
            type: NotificationType::ScheduledRideReserved,
            title: 'Driver Reserved',
            body: "{$driver->first_name} will pick you up at {$time}.",
            data: ['ride_id' => (string) $ride->id],
        ));

        if ($byAdmin) {
            $this->safely(fn () => $this->notificationService->send(
                userId: $driver->id,
                type: NotificationType::ScheduledRideReserved,
                title: 'Scheduled Pickup Assigned',
                body: "You have a reserved pickup at {$time} from {$ride->pickup_address}.",
                data: ['ride_id' => (string) $ride->id],
            ));
        }
    }

    private function notifyReleased(Ride $ride, string $driverId, string $reason): void
    {
        // The passenger caused a pickup_changed release themselves.
        $passengerBody = match ($reason) {
            'pickup_changed' => null,
            'driver_unavailable' => 'Your reserved driver is not available, so we are finding you another driver now.',
            default => 'Your reserved driver can no longer make it. We will find you another driver at pickup time.',
        };

        if ($passengerBody !== null) {
            $this->safely(fn () => $this->notificationService->send(
                userId: $ride->passenger_id,
                type: NotificationType::ScheduledRideReservationReleased,
                title: 'Your Booking Is Still On',
                body: $passengerBody,
                data: ['ride_id' => (string) $ride->id],
            ));
        }

        if ($reason !== 'driver_released') {
            $body = match ($reason) {
                'driver_unavailable' => 'You were offline or on another trip when your reserved pickup started, so it went to another driver.',
                'pickup_changed' => 'The passenger changed the pickup time or place, so your reservation was released.',
                'vehicle_blocked' => "Your car's papers have expired, so your reserved pickup was released. Upload the renewed paper to drive again.",
                'shift_changed' => 'Your shifts changed and this reserved pickup is no longer inside one, so it was released.',
                'driver_suspended' => 'Your account is not active, so your reserved pickup was released.',
                default => 'Operations released your reserved pickup.',
            };

            $this->safely(fn () => $this->notificationService->send(
                userId: $driverId,
                type: NotificationType::ScheduledRideReservationReleased,
                title: 'Reservation Released',
                body: $body,
                data: ['ride_id' => (string) $ride->id],
            ));
        }
    }

    private function localTime(Ride $ride, ScheduledRide $scheduled): string
    {
        $ride->loadMissing('city');

        return $scheduled->scheduled_pickup_at->copy()
            ->setTimezone($ride->city?->timezone ?? config('app.timezone'))
            ->format('D j M, g:i A');
    }

    private function safely(\Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('Scheduled ride reservation notification failed', ['error' => $e->getMessage()]);
        }
    }
}
