<?php

namespace App\Services;

use App\Enums\DriverShiftStatus;
use App\Enums\FleetAgreementStatus;
use App\Enums\NotificationType;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Shifts on shared fleet cars (Phase 2, OQ-20). Only fleet cars in shift
 * mode have a timetable; every other driver chooses when to drive.
 *
 * The car's driver_id is whoever holds it now. When a shift driver goes
 * online in their window the car passes to them, provided the previous
 * driver is offline and not on a trip. Shift drivers can go online from
 * shift_online_lead_minutes before the start until the end; new offers stop
 * shift_offer_cutoff_minutes before the end, and a trip running past the end
 * is finished.
 */
class DriverShiftService
{
    public const TIMEZONE = 'Africa/Lagos';

    public function __construct(
        private NotificationService $notifications,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return AppSetting::driverScheduling();
    }

    /**
     * Operations assign a shift, optionally repeated on the following days
     * (a rota). All of them are created or none.
     *
     * @param  array{driver_id: string, vehicle_id: string, starts_at: string, ends_at: string, notes?: ?string, repeat_daily_for?: ?int}  $data
     * @return Collection<int, DriverShift>
     *
     * @throws ValidationException
     */
    public function create(User $admin, array $data): Collection
    {
        $settings = $this->settings();

        if (! $settings['shifts_enabled']) {
            throw ValidationException::withMessages(['shifts' => ['Shift scheduling is turned off in the driver scheduling settings.']]);
        }

        $startsAt = Carbon::parse($data['starts_at'])->utc();
        $endsAt = Carbon::parse($data['ends_at'])->utc();
        $repeat = (int) ($data['repeat_daily_for'] ?? 0);

        $shifts = DB::transaction(function () use ($admin, $data, $startsAt, $endsAt, $repeat, $settings) {
            [$vehicle, $driver] = $this->lockVehicleAndDriver($data['vehicle_id'], $data['driver_id']);
            $this->assertCanBeAssigned($vehicle, $driver);

            $created = collect();

            for ($day = 0; $day <= $repeat; $day++) {
                $start = $startsAt->copy()->addDays($day);
                $end = $endsAt->copy()->addDays($day);
                $this->assertTimes($start, $end, $settings);
                $this->assertNoOverlap($vehicle, $driver, $start, $end);

                $shift = DriverShift::create([
                    'driver_id' => $driver->id,
                    'vehicle_id' => $vehicle->id,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'status' => DriverShiftStatus::Scheduled,
                    'notes' => $data['notes'] ?? null,
                    'created_by_admin_id' => $admin->id,
                ]);

                AuditLog::record($shift, 'driver_shift_created', $admin, null, [
                    'driver_id' => $driver->id,
                    'vehicle_id' => $vehicle->id,
                    'starts_at' => $start->toIso8601String(),
                    'ends_at' => $end->toIso8601String(),
                ]);

                $created->push($shift);
            }

            return $created;
        });

        $first = $shifts->first()->load(['vehicle', 'driver']);
        $count = $shifts->count();

        $this->notify(
            $first->driver,
            NotificationType::DriverShiftAssigned,
            $count === 1
                ? "You have a shift with {$first->vehicle->plate_number}: {$this->window($first)}."
                : "You have {$count} shifts with {$first->vehicle->plate_number}, starting {$this->window($first)}.",
            $first,
        );

        return $shifts;
    }

    /**
     * Change a shift. Before it starts: driver, car, times and notes. Once it
     * is in progress: only the end time (to extend it or end it early) and
     * notes.
     *
     * @param  array{driver_id?: string, vehicle_id?: string, starts_at?: string, ends_at?: string, notes?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function update(DriverShift $shift, User $admin, array $data): DriverShift
    {
        $settings = $this->settings();
        $previousDriverId = $shift->driver_id;

        $shift = DB::transaction(function () use ($shift, $admin, $data, $settings) {
            $shift = DriverShift::lockForUpdate()->findOrFail($shift->id);

            if ($shift->status === DriverShiftStatus::Active) {
                $notAllowed = array_diff(array_keys($data), ['ends_at', 'notes']);

                if ($notAllowed !== []) {
                    throw ValidationException::withMessages(['shift' => ['A shift in progress can only have its end time or notes changed.']]);
                }
            } elseif (! in_array($shift->status, [DriverShiftStatus::Scheduled, DriverShiftStatus::Missed], true)) {
                throw ValidationException::withMessages(['shift' => ["A {$shift->status->label()} shift can no longer be changed."]]);
            }

            [$vehicle, $driver] = $this->lockVehicleAndDriver($data['vehicle_id'] ?? $shift->vehicle_id, $data['driver_id'] ?? $shift->driver_id);

            if ($vehicle->id !== $shift->vehicle_id || $driver->id !== $shift->driver_id) {
                $this->assertCanBeAssigned($vehicle, $driver);
            }

            $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at'])->utc() : $shift->starts_at;
            $endsAt = isset($data['ends_at']) ? Carbon::parse($data['ends_at'])->utc() : $shift->ends_at;

            if ($shift->status === DriverShiftStatus::Active) {
                if ($endsAt->lte(now())) {
                    throw ValidationException::withMessages(['ends_at' => ['The end time must be in the future.']]);
                }

                $this->assertDuration($startsAt, $endsAt, $settings);
            } else {
                $this->assertTimes($startsAt, $endsAt, $settings);
            }

            $this->assertNoOverlap($vehicle, $driver, $startsAt, $endsAt, $shift->id);

            $old = $shift->only(['driver_id', 'vehicle_id', 'starts_at', 'ends_at', 'notes']);
            $shift->fill([
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $shift->notes,
            ]);

            // A missed shift moved to a later start is scheduled again.
            if ($shift->status === DriverShiftStatus::Missed && $shift->isDirty('starts_at') && $startsAt->isFuture()) {
                $shift->status = DriverShiftStatus::Scheduled;
                $shift->missed_at = null;
            }

            if ($shift->isDirty('ends_at')) {
                $shift->end_warning_sent_at = null;
            }

            $shift->save();

            AuditLog::record($shift, 'driver_shift_updated', $admin, $old, $shift->only(['driver_id', 'vehicle_id', 'starts_at', 'ends_at', 'notes']));

            return $shift;
        });

        $shift->load(['vehicle', 'driver']);

        if ($previousDriverId !== $shift->driver_id) {
            $previous = Driver::find($previousDriverId);
            $this->notify($previous, NotificationType::DriverShiftCancelled, "Your shift with {$shift->vehicle->plate_number} ({$this->window($shift)}) was given to another driver.", $shift);
            $this->notify($shift->driver, NotificationType::DriverShiftAssigned, "You have a shift with {$shift->vehicle->plate_number}: {$this->window($shift)}.", $shift);
            $this->releaseReservationsOutsideShifts($previous);
        } else {
            $this->notify($shift->driver, NotificationType::DriverShiftUpdated, "Your shift with {$shift->vehicle->plate_number} is now {$this->window($shift)}.", $shift);
        }

        $this->releaseReservationsOutsideShifts($shift->driver);

        return $shift;
    }

    /**
     * @throws ValidationException
     */
    public function cancel(DriverShift $shift, User $admin, string $reason): DriverShift
    {
        $shift = DB::transaction(function () use ($shift, $admin, $reason) {
            $shift = DriverShift::lockForUpdate()->findOrFail($shift->id);

            if (! in_array($shift->status, [DriverShiftStatus::Scheduled, DriverShiftStatus::Missed], true)) {
                throw ValidationException::withMessages(['shift' => $shift->status === DriverShiftStatus::Active
                    ? ['A shift in progress cannot be cancelled. Change its end time to end it early.']
                    : ["A {$shift->status->label()} shift cannot be cancelled."]]);
            }

            $shift->update([
                'status' => DriverShiftStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            AuditLog::record($shift, 'driver_shift_cancelled', $admin, null, ['reason' => $reason]);

            return $shift;
        });

        $shift->load(['vehicle', 'driver']);
        $this->notify($shift->driver, NotificationType::DriverShiftCancelled, "Your shift with {$shift->vehicle->plate_number} ({$this->window($shift)}) was cancelled: {$reason}", $shift);
        $this->releaseReservationsOutsideShifts($shift->driver);

        return $shift;
    }

    /**
     * The driver can no longer drive (suspended, deactivated or rejected):
     * a shift in progress ends now and upcoming ones are cancelled, so
     * operations can give the car to another driver straight away rather
     * than wait for the shifts to be missed. Operations are told which
     * slots were freed. Returns how many shifts were freed.
     */
    public function withdrawDriver(Driver $driver, string $reason): int
    {
        $lines = [];

        $driver->shifts()
            ->workable()
            ->where('ends_at', '>', now())
            ->pluck('id')
            ->each(function (string $id) use ($reason, &$lines) {
                DB::transaction(function () use ($id, $reason, &$lines) {
                    $shift = DriverShift::lockForUpdate()->with('vehicle')->find($id);

                    if ($shift === null || ! $shift->isWorkable() || $shift->ends_at->lte(now())) {
                        return;
                    }

                    if ($shift->status === DriverShiftStatus::Active) {
                        $shift->update(['status' => DriverShiftStatus::Completed, 'ended_at' => now(), 'ends_at' => now()]);
                    } else {
                        $shift->update(['status' => DriverShiftStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
                    }

                    AuditLog::record($shift, 'driver_shift_withdrawn', null, null, ['reason' => $reason, 'status' => $shift->status->value]);

                    $lines[] = "{$shift->vehicle?->plate_number}: {$this->window($shift)}";
                });
            });

        if ($lines !== []) {
            $name = trim(($driver->user?->first_name ?? '').' '.($driver->user?->last_name ?? '')) ?: 'A driver';

            app(OperationsAlertService::class)->send(
                label: 'Shifts freed',
                headline: count($lines) === 1 ? '1 fleet shift needs a new driver' : count($lines).' fleet shifts need a new driver',
                body: "{$name} can no longer drive ({$reason}), so their shifts were cancelled. Give these slots to another driver.",
                lines: $lines,
                actionPath: '/admin/driver-shifts?status=cancelled',
                actionLabel: 'Open shifts',
            );
        }

        return count($lines);
    }

    /**
     * Put a fleet car on a shift timetable, or take it off one. A car on
     * hire purchase belongs to the driver buying it, so it cannot be shared.
     *
     * @throws ValidationException
     */
    public function setShiftMode(Vehicle $vehicle, bool $shiftMode, User $admin): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $shiftMode, $admin) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($vehicle->id);

            if ($vehicle->shift_mode === $shiftMode) {
                return $vehicle;
            }

            if ($shiftMode) {
                if (! $vehicle->is_fleet) {
                    throw ValidationException::withMessages(['shift_mode' => ['Only E-tiGo fleet cars can be shared on shifts.']]);
                }

                if ($vehicle->fleetAgreements()->where('status', FleetAgreementStatus::Active)->exists()) {
                    throw ValidationException::withMessages(['shift_mode' => ['This car is on a hire-purchase agreement with one driver, so it cannot be shared. End the agreement first.']]);
                }
            } else {
                $upcoming = $vehicle->shifts()->workable()->where('ends_at', '>', now())->count();

                if ($upcoming > 0) {
                    throw ValidationException::withMessages(['shift_mode' => ["Cancel this car's {$upcoming} upcoming shift(s) first."]]);
                }
            }

            $vehicle->update(['shift_mode' => $shiftMode]);
            AuditLog::record($vehicle, $shiftMode ? 'vehicle_shift_mode_on' : 'vehicle_shift_mode_off', $admin);

            return $vehicle;
        });
    }

    /**
     * Whether shift rules decide when this driver can drive: they hold a
     * shared car, or they have no car of their own and are on a timetable.
     */
    public function isShiftDriver(Driver $driver): bool
    {
        if (! $this->settings()['shifts_enabled']) {
            return false;
        }

        $vehicle = $driver->vehicle;

        if ($vehicle !== null) {
            return (bool) $vehicle->shift_mode;
        }

        return $driver->shifts()->workable()->where('ends_at', '>', now())->exists();
    }

    /**
     * The shift the driver can be online for now (from the lead before its
     * start until its end).
     */
    public function openShift(Driver $driver): ?DriverShift
    {
        return $driver->shifts()
            ->openAt(now(), $this->settings()['shift_online_lead_minutes'])
            ->with('vehicle.driver.user')
            ->orderBy('starts_at')
            ->first();
    }

    public function nextShift(Driver $driver): ?DriverShift
    {
        return $driver->shifts()
            ->workable()
            ->where('ends_at', '>', now())
            ->with('vehicle')
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Shift rules for going online. Returns the reasons the driver cannot,
     * the open shift, and the shared car to hand over to them (when another
     * driver, now offline, still holds it).
     *
     * @return array{errors: list<string>, shift: ?DriverShift, handover: ?Vehicle}
     */
    public function onlineCheck(Driver $driver): array
    {
        $none = ['errors' => [], 'shift' => null, 'handover' => null];

        if (! $this->isShiftDriver($driver)) {
            return $none;
        }

        $settings = $this->settings();
        $shift = $this->openShift($driver);

        if ($shift === null) {
            $next = $this->nextShift($driver);

            return [
                ...$none,
                'errors' => [$next !== null
                    ? "Your car is shared on shifts. Your next shift starts {$this->localTime($next->starts_at)}; you can go online from {$this->localTime($next->onlineFrom($settings['shift_online_lead_minutes']))}."
                    : 'Your car is shared on shifts and you have no shift now. Contact fleet operations for your next shift.'],
            ];
        }

        $car = $shift->vehicle;

        if ($car->driver_id === $driver->id) {
            return [...$none, 'shift' => $shift];
        }

        if ($car->driver_id !== null) {
            $holder = $car->driver;
            $holderOnTrip = Ride::where('driver_id', $holder->user_id)->whereIn('status', RideStatus::driverOnTripStatuses())->exists();

            if ($holder->is_online || $holderOnTrip) {
                $name = $holder->user?->first_name ?? 'the previous driver';

                return [
                    ...$none,
                    'shift' => $shift,
                    'errors' => [$holderOnTrip
                        ? "{$car->plate_number} is still with {$name}, who is finishing a trip. You can go online once the car is handed over."
                        : "{$car->plate_number} is still with {$name}, who is online. You can go online once the car is handed over."],
                ];
            }
        }

        return [...$none, 'shift' => $shift, 'handover' => $car];
    }

    /**
     * The shift driver goes online with the shared car, in one transaction
     * under the car's and both drivers' row locks: the car passes to them if
     * the previous driver is offline and not on a trip, any other shared car
     * they still hold is released, and the shift is marked started.
     *
     * @throws ValidationException
     */
    public function goOnlineForShift(Driver $driver, DriverShift $shift): void
    {
        DB::transaction(function () use ($driver, $shift) {
            // Operations may have cancelled, moved or reassigned the shift
            // since the check: confirm it under its lock.
            $shift = DriverShift::lockForUpdate()->findOrFail($shift->id);
            $lead = $this->settings()['shift_online_lead_minutes'];

            if ($shift->driver_id !== $driver->id
                || ! $shift->isWorkable()
                || $shift->ends_at->lte(now())
                || $shift->onlineFrom($lead)->gt(now())) {
                throw ValidationException::withMessages(['shift' => ['Your shift has changed. Check your shifts and try again.']]);
            }

            $car = Vehicle::lockForUpdate()->findOrFail($shift->vehicle_id);
            $driver = Driver::lockForUpdate()->findOrFail($driver->id);

            if ($car->driver_id !== $driver->id) {
                $previous = $car->driver_id !== null ? Driver::lockForUpdate()->find($car->driver_id) : null;

                if ($previous !== null
                    && ($previous->is_online || Ride::where('driver_id', $previous->user_id)->whereIn('status', RideStatus::driverOnTripStatuses())->exists())) {
                    throw ValidationException::withMessages(['vehicle' => ["{$car->plate_number} is still in use by the previous driver."]]);
                }

                $other = Vehicle::where('driver_id', $driver->id)->where('id', '!=', $car->id)->lockForUpdate()->get();

                if ($other->contains(fn (Vehicle $vehicle) => ! $vehicle->shift_mode)) {
                    throw ValidationException::withMessages(['vehicle' => ['You have another car on E-tiGo. Contact fleet operations.']]);
                }

                $other->each(fn (Vehicle $vehicle) => $vehicle->update(['driver_id' => null]));
                $car->update(['driver_id' => $driver->id]);

                AuditLog::record($car, 'shift_vehicle_handover', null, ['driver_id' => $previous?->id], ['driver_id' => $driver->id]);
            }

            $driver->update(['is_online' => true]);
            $this->markStarted($shift);
        });
    }

    /**
     * The driver went online in their shift window.
     */
    public function markStarted(DriverShift $shift): void
    {
        DriverShift::whereKey($shift->id)
            ->whereIn('status', [DriverShiftStatus::Scheduled, DriverShiftStatus::Missed])
            ->update(['status' => DriverShiftStatus::Active, 'started_at' => now()]);
    }

    /**
     * Drivers new offers can go to, as far as shifts are concerned: cars not
     * on a timetable, or a shift driver whose shift has not reached the
     * offer cut-off.
     */
    public function constrainDispatchable(Builder $driverQuery, ?Carbon $at = null): void
    {
        $settings = $this->settings();

        if (! $settings['shifts_enabled']) {
            return;
        }

        $at ??= now();

        $driverQuery->where(fn (Builder $q) => $q
            ->whereHas('vehicle', fn (Builder $v) => $v->where('shift_mode', false))
            ->orWhere(fn (Builder $shared) => $shared
                ->whereHas('vehicle', fn (Builder $v) => $v->where('shift_mode', true))
                ->whereHas('shifts', fn (Builder $s) => $s
                    ->workable()
                    ->where('starts_at', '<=', $at->copy()->addMinutes($settings['shift_online_lead_minutes']))
                    ->where('ends_at', '>', $at->copy()->addMinutes($settings['shift_offer_cutoff_minutes'])))));
    }

    /**
     * For a shift driver, the shift (with its car) that a scheduled pickup at
     * $pickupAt falls in, early enough to be done before the offer cut-off.
     */
    public function shiftCovering(Driver $driver, Carbon $pickupAt): ?DriverShift
    {
        $settings = $this->settings();

        return $driver->shifts()
            ->workable()
            ->where('starts_at', '<=', $pickupAt)
            ->where('ends_at', '>', $pickupAt->copy()->addMinutes($settings['shift_offer_cutoff_minutes']))
            ->with('vehicle')
            ->first();
    }

    /**
     * Release the driver's reserved scheduled pickups that no longer fall in
     * one of their shifts (after a shift is cancelled, moved or reassigned).
     */
    public function releaseReservationsOutsideShifts(?Driver $driver): void
    {
        // Drivers of a car not on a timetable reserve with that car; a driver
        // whose last shift was just cancelled has none left to cover them.
        if ($driver === null || ! $this->settings()['shifts_enabled'] || ($driver->vehicle !== null && ! $driver->vehicle->shift_mode)) {
            return;
        }

        $reservations = app(ScheduledRideReservationService::class);

        ScheduledRide::query()
            ->upcoming()
            ->where('reserved_driver_id', $driver->user_id)
            ->get()
            ->reject(fn (ScheduledRide $scheduled) => $this->shiftCovering($driver, $scheduled->scheduled_pickup_at) !== null)
            ->each(function (ScheduledRide $scheduled) use ($driver, $reservations) {
                try {
                    DB::transaction(function () use ($scheduled, $driver, $reservations) {
                        $ride = Ride::lockForUpdate()->find($scheduled->ride_id);
                        $locked = ScheduledRide::lockForUpdate()->find($scheduled->id);

                        if ($ride !== null && $locked !== null && $locked->isUpcoming() && $locked->reserved_driver_id === $driver->user_id) {
                            $reservations->clearReservation($ride, $locked, 'shift_changed');
                        }
                    });
                } catch (\Throwable $e) {
                    Log::warning('Could not release reservation outside shifts', ['scheduled_ride_id' => $scheduled->id, 'error' => $e->getMessage()]);
                }
            });
    }

    public function window(DriverShift $shift): string
    {
        $start = $shift->starts_at->copy()->setTimezone(self::TIMEZONE);
        $end = $shift->ends_at->copy()->setTimezone(self::TIMEZONE);

        return $start->format('D j M, g:i A').' – '.($start->isSameDay($end) ? $end->format('g:i A') : $end->format('D j M, g:i A'));
    }

    public function localTime(Carbon $at): string
    {
        return $at->copy()->setTimezone(self::TIMEZONE)->format('D j M, g:i A');
    }

    public function notify(?Driver $driver, NotificationType $type, string $body, ?DriverShift $shift = null): void
    {
        if ($driver === null) {
            return;
        }

        try {
            $this->notifications->send(
                userId: $driver->user_id,
                type: $type,
                title: $type->label(),
                body: $body,
                data: array_filter(['shift_id' => $shift?->id ? (string) $shift->id : null]),
            );
        } catch (\Throwable $e) {
            Log::warning('Driver shift notification failed', ['driver_id' => $driver->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{0: Vehicle, 1: Driver}
     */
    private function lockVehicleAndDriver(string $vehicleId, string $driverId): array
    {
        $vehicle = Vehicle::lockForUpdate()->find($vehicleId);
        $driver = Driver::with('user')->lockForUpdate()->find($driverId);

        if ($vehicle === null) {
            throw ValidationException::withMessages(['vehicle_id' => ['Vehicle not found.']]);
        }

        if ($driver === null) {
            throw ValidationException::withMessages(['driver_id' => ['Driver not found.']]);
        }

        return [$vehicle, $driver];
    }

    /**
     * @throws ValidationException
     */
    private function assertCanBeAssigned(Vehicle $vehicle, Driver $driver): void
    {
        if (! $vehicle->is_fleet || ! $vehicle->shift_mode) {
            throw ValidationException::withMessages(['vehicle_id' => ['Shifts are only for fleet cars in shift mode. Turn shift mode on for this car first.']]);
        }

        if ($vehicle->isBlocked()) {
            throw ValidationException::withMessages(['vehicle_id' => ["This car can't be driven: {$vehicle->block_reason}"]]);
        }

        if (! $driver->isApproved()) {
            throw ValidationException::withMessages(['driver_id' => ['Only approved drivers can be given shifts.']]);
        }

        $otherCar = Vehicle::where('driver_id', $driver->id)->where('shift_mode', false)->first();

        if ($otherCar !== null) {
            throw ValidationException::withMessages(['driver_id' => ["This driver drives another car on E-tiGo ({$otherCar->plate_number}). A driver can be given shifts on a shared car only while they have no car of their own."]]);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws ValidationException
     */
    private function assertTimes(Carbon $startsAt, Carbon $endsAt, array $settings): void
    {
        if ($endsAt->lte(now())) {
            throw ValidationException::withMessages(['ends_at' => ['The shift must end in the future.']]);
        }

        if ($startsAt->gt(now()->addDays($settings['shift_max_advance_days']))) {
            throw ValidationException::withMessages(['starts_at' => ["Shifts can be planned up to {$settings['shift_max_advance_days']} days ahead."]]);
        }

        $this->assertDuration($startsAt, $endsAt, $settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws ValidationException
     */
    private function assertDuration(Carbon $startsAt, Carbon $endsAt, array $settings): void
    {
        $minutes = $startsAt->diffInMinutes($endsAt, false);

        if ($minutes < $settings['shift_min_minutes']) {
            throw ValidationException::withMessages(['ends_at' => ["A shift must last at least {$settings['shift_min_minutes']} minutes."]]);
        }

        if ($minutes > $settings['shift_max_hours'] * 60) {
            throw ValidationException::withMessages(['ends_at' => ["A shift can last at most {$settings['shift_max_hours']} hours."]]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNoOverlap(Vehicle $vehicle, Driver $driver, Carbon $startsAt, Carbon $endsAt, ?string $exceptId = null): void
    {
        $overlapping = fn (string $column, string $id) => DriverShift::query()
            ->holdingSlot()
            ->where($column, $id)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->overlapping($startsAt, $endsAt)
            ->with(['driver.user', 'vehicle'])
            ->first();

        if ($clash = $overlapping('vehicle_id', $vehicle->id)) {
            $name = $clash->driver?->user?->first_name ?? 'another driver';

            throw ValidationException::withMessages(['starts_at' => ["{$vehicle->plate_number} already has a shift then ({$name}, {$this->window($clash)})."]]);
        }

        if ($clash = $overlapping('driver_id', $driver->id)) {
            throw ValidationException::withMessages(['starts_at' => ["This driver already has a shift then ({$clash->vehicle?->plate_number}, {$this->window($clash)})."]]);
        }
    }
}
