<?php

namespace App\Jobs;

use App\Enums\DriverShiftStatus;
use App\Enums\NotificationType;
use App\Enums\RideStatus;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\Ride;
use App\Services\DriverLocationService;
use App\Services\DriverShiftService;
use App\Services\OperationsAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every 5 minutes, for shifts on shared fleet cars (OQ-20):
 *
 * - a shift whose driver is not online 30 min after the start is marked
 *   missed, and operations are alerted to reassign the car (no fine);
 * - the driver is told when new offers stop, shortly before the end;
 * - the next driver is told when the car may be late because the previous
 *   driver is still on a trip;
 * - a shift past its end is completed and its driver set offline, unless on
 *   a trip (finished first) or straight into their next shift on the car.
 *
 * Each shift is handled on its own: one failure is logged and skipped.
 */
class ProcessDriverShiftsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(DriverShiftService $shifts, DriverLocationService $locations, OperationsAlertService $operations): void
    {
        $settings = $shifts->settings();

        if (! $settings['shifts_enabled']) {
            return;
        }

        $missed = $this->markMissed($shifts, $settings['shift_missed_after_minutes']);

        if ($missed !== []) {
            $operations->send(
                label: 'Shift missed',
                headline: count($missed) === 1 ? '1 fleet shift has not started' : count($missed).' fleet shifts have not started',
                body: 'These drivers were not online '.$settings['shift_missed_after_minutes'].' minutes after their shift started. Reassign the car or contact the driver.',
                lines: $missed,
                actionPath: '/admin/driver-shifts?status=missed',
                actionLabel: 'Open shifts',
            );
        }

        $this->warnOffersStopping($shifts, $settings['shift_offer_cutoff_minutes']);
        $this->warnHandoverDelays($shifts, $settings['shift_online_lead_minutes']);
        $this->endShifts($shifts, $locations, $settings['shift_online_lead_minutes']);
    }

    /**
     * @return list<string> lines for the operations alert
     */
    private function markMissed(DriverShiftService $shifts, int $afterMinutes): array
    {
        $lines = [];

        DriverShift::query()
            ->where('status', DriverShiftStatus::Scheduled)
            ->where('starts_at', '<=', now()->subMinutes($afterMinutes))
            ->with(['driver.user', 'vehicle'])
            ->lazyById()
            ->each(function (DriverShift $shift) use ($shifts, &$lines) {
                $this->safely($shift, function () use ($shift, $shifts, &$lines) {
                    $updated = DriverShift::whereKey($shift->id)
                        ->where('status', DriverShiftStatus::Scheduled)
                        ->update(['status' => DriverShiftStatus::Missed, 'missed_at' => now()]);

                    if ($updated === 0) {
                        return;
                    }

                    AuditLog::record($shift, 'driver_shift_missed');

                    $shifts->notify(
                        $shift->driver,
                        NotificationType::DriverShiftMissed,
                        "You haven't gone online for your shift with {$shift->vehicle->plate_number} ({$shifts->window($shift)}). Fleet operations have been told. Go online now if you can still drive.",
                        $shift,
                    );

                    $name = trim(($shift->driver?->user?->first_name ?? '').' '.($shift->driver?->user?->last_name ?? ''));
                    $lines[] = "{$shift->vehicle->plate_number}: {$name}, {$shifts->window($shift)}";
                });
            });

        return $lines;
    }

    private function warnOffersStopping(DriverShiftService $shifts, int $cutoffMinutes): void
    {
        DriverShift::query()
            ->where('status', DriverShiftStatus::Active)
            ->whereNull('end_warning_sent_at')
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addMinutes($cutoffMinutes))
            ->with(['driver', 'vehicle'])
            ->lazyById()
            ->each(fn (DriverShift $shift) => $this->safely($shift, function () use ($shift, $shifts) {
                $shift->update(['end_warning_sent_at' => now()]);

                // Straight into another shift on the same car: nothing stops.
                if ($this->nextShiftOnSameCar($shift) !== null) {
                    return;
                }

                $shifts->notify(
                    $shift->driver,
                    NotificationType::DriverShiftEnding,
                    "Your shift ends at {$shift->ends_at->copy()->setTimezone(DriverShiftService::TIMEZONE)->format('g:i A')}. You won't get new trips now; finish your current trip and hand the car over.",
                    $shift,
                );
            }));
    }

    private function warnHandoverDelays(DriverShiftService $shifts, int $leadMinutes): void
    {
        DriverShift::query()
            ->where('status', DriverShiftStatus::Scheduled)
            ->whereNull('handover_delay_notified_at')
            ->where('starts_at', '<=', now()->addMinutes($leadMinutes))
            ->where('ends_at', '>', now())
            ->with(['driver', 'vehicle.driver.user'])
            ->lazyById()
            ->each(fn (DriverShift $shift) => $this->safely($shift, function () use ($shift, $shifts) {
                $holder = $shift->vehicle->driver;

                if ($holder === null || $holder->id === $shift->driver_id) {
                    return;
                }

                $onTrip = Ride::where('driver_id', $holder->user_id)->whereIn('status', RideStatus::driverOnTripStatuses())->exists();

                if (! $onTrip) {
                    return;
                }

                $shift->update(['handover_delay_notified_at' => now()]);

                $shifts->notify(
                    $shift->driver,
                    NotificationType::ShiftHandoverDelayed,
                    "{$shift->vehicle->plate_number} may be a little late: {$holder->user?->first_name} is finishing a trip. You can go online once the car is handed over.",
                    $shift,
                );
            }));
    }

    private function endShifts(DriverShiftService $shifts, DriverLocationService $locations, int $leadMinutes): void
    {
        DriverShift::query()
            ->whereIn('status', [DriverShiftStatus::Scheduled, DriverShiftStatus::Active])
            ->where('ends_at', '<=', now())
            ->with(['driver', 'vehicle'])
            ->lazyById()
            ->each(fn (DriverShift $shift) => $this->safely($shift, function () use ($shift, $shifts, $locations) {
                // Never started (shorter than the missed threshold): missed.
                if ($shift->status === DriverShiftStatus::Scheduled) {
                    $shift->update(['status' => DriverShiftStatus::Missed, 'missed_at' => now()]);
                    AuditLog::record($shift, 'driver_shift_missed');

                    return;
                }

                $driver = $shift->driver;
                $holdsCar = $shift->vehicle->driver_id === $driver->id;
                $next = $this->nextShiftOnSameCar($shift);
                $setOffline = false;

                if ($holdsCar && $next === null && $driver->is_online) {
                    $onTrip = Ride::where('driver_id', $driver->user_id)->whereIn('status', RideStatus::driverOnTripStatuses())->exists();

                    // The trip finishes; the shift ends on a later run.
                    if ($onTrip) {
                        return;
                    }

                    $this->setOffline($driver, $locations);
                    $setOffline = true;
                }

                $shift->update(['status' => DriverShiftStatus::Completed, 'ended_at' => now()]);
                AuditLog::record($shift, 'driver_shift_completed');

                if ($next !== null) {
                    $shifts->markStarted($next);

                    return;
                }

                $shifts->notify(
                    $driver,
                    NotificationType::DriverShiftEnded,
                    $setOffline
                        ? "Your shift with {$shift->vehicle->plate_number} has ended and you're now offline. Please hand the car over."
                        : "Your shift with {$shift->vehicle->plate_number} has ended.",
                    $shift,
                );
            }));
    }

    /**
     * The same driver's next shift on the same car, starting when this one
     * ends (back-to-back shifts).
     */
    private function nextShiftOnSameCar(DriverShift $shift): ?DriverShift
    {
        return DriverShift::query()
            ->workable()
            ->where('id', '!=', $shift->id)
            ->where('driver_id', $shift->driver_id)
            ->where('vehicle_id', $shift->vehicle_id)
            ->where('starts_at', '<=', $shift->ends_at)
            ->where('ends_at', '>', $shift->ends_at)
            ->first();
    }

    private function setOffline(Driver $driver, DriverLocationService $locations): void
    {
        DB::transaction(function () use ($driver) {
            $driver->refresh();
            $driver->update(['is_online' => false]);
            AuditLog::record($driver, 'driver_set_offline_shift_ended');
        });

        try {
            $locations->removeDriver($driver->id, $driver->user_id);
        } catch (\Throwable $e) {
            Log::warning('Failed to remove driver location at shift end', ['driver_id' => $driver->id, 'error' => $e->getMessage()]);
        }
    }

    private function safely(DriverShift $shift, \Closure $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            Log::error('Driver shift processing failed', ['shift_id' => $shift->id, 'error' => $e->getMessage()]);
        }
    }
}
