<?php

namespace App\Services;

use App\Contracts\PushNotificationGateway;
use App\Enums\EvReservationStatus;
use App\Enums\NotificationType;
use App\Jobs\TransferReservationJob;
use App\Models\EvChargingStall;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReservationService
{
    public function __construct(
        private readonly StallAvailabilityService $stallService,
        private readonly TierGateService $tierGate,
        private readonly EvReservationFeeService $fees,
        private readonly PushNotificationGateway $pushGateway,
    ) {}

    public function reserve(EvChargingStation $station, User $driver, ?int $estimatedChargeMinutes = null): array
    {
        return DB::transaction(function () use ($station, $driver, $estimatedChargeMinutes) {
            EvChargingStation::where('id', $station->id)->lockForUpdate()->first();
            User::where('id', $driver->id)->lockForUpdate()->first();

            $this->validateDriverEligibility($driver);

            $availableStall = $this->stallService->findAvailableStall($station);

            if ($availableStall) {
                return $this->immediateReservation($station, $availableStall, $driver, $estimatedChargeMinutes);
            }

            $imminentStall = $this->stallService->findImminentDepartureStall($station);

            if ($imminentStall) {
                return $this->queuedReservation($station, $imminentStall, $driver);
            }

            return $this->retryLater($station);
        });
    }

    public function activate(EvReservation $reservation, ?int $estimatedChargeMinutes = null): EvReservation
    {
        return DB::transaction(function () use ($reservation, $estimatedChargeMinutes) {
            $reservation = EvReservation::lockForUpdate()->find($reservation->id);

            if ($reservation->status !== EvReservationStatus::Reserved) {
                throw new \RuntimeException('Only reserved reservations can be activated.');
            }

            $reservation->update([
                'status' => EvReservationStatus::Active,
                'activated_at' => now(),
            ]);

            if ($reservation->stall) {
                $this->stallService->occupyStall($reservation->stall, $reservation->driver_id, $estimatedChargeMinutes);
            }

            Log::info('EV reservation activated', [
                'reservation_id' => $reservation->id,
                'stall_id' => $reservation->stall_id,
            ]);

            return $reservation->fresh();
        });
    }

    public function complete(EvReservation $reservation): EvReservation
    {
        return DB::transaction(function () use ($reservation) {
            $reservation = EvReservation::lockForUpdate()->find($reservation->id);

            if (! in_array($reservation->status, [EvReservationStatus::Active, EvReservationStatus::Reserved])) {
                throw new \RuntimeException('Only active or reserved reservations can be completed.');
            }

            $reservation->update([
                'status' => EvReservationStatus::Completed,
                'completed_at' => now(),
            ]);

            if ($reservation->stall_id) {
                $this->stallService->releaseStall($reservation->stall);
                TransferReservationJob::dispatch($reservation->station_id)->afterCommit();
            }

            Log::info('EV reservation completed', ['reservation_id' => $reservation->id]);

            return $reservation->fresh();
        });
    }

    public function cancel(EvReservation $reservation): EvReservation
    {
        return DB::transaction(function () use ($reservation) {
            $reservation = EvReservation::lockForUpdate()->find($reservation->id);

            if ($reservation->status->isTerminal()) {
                throw new \RuntimeException('Cannot cancel a terminal reservation.');
            }

            $wasQueued = $reservation->status === EvReservationStatus::Queued;
            $hadStall = $reservation->stall_id !== null;
            $station = $reservation->station;

            $reservation->update([
                'status' => EvReservationStatus::Cancelled,
            ]);

            $this->fees->refundIfWithinGracePeriod($reservation);

            if ($hadStall && $reservation->stall) {
                $this->stallService->releaseStall($reservation->stall);
                TransferReservationJob::dispatch($station->id)->afterCommit();
            }

            if ($wasQueued) {
                $this->reorderQueue($reservation->station_id);
            }

            Log::info('EV reservation cancelled', ['reservation_id' => $reservation->id]);

            return $reservation->fresh();
        });
    }

    /**
     * Release reserved stalls whose driver never arrived within the expiry window.
     *
     * @return int Number of reservations expired
     */
    public function expireOverdueReservations(): int
    {
        $overdue = EvReservation::where('status', EvReservationStatus::Reserved)
            ->where('reserved_at', '<=', now()->subMinutes((int) config('ev_charging.reservation_expiry_minutes', 30)))
            ->get();

        foreach ($overdue as $reservation) {
            $this->expire($reservation);
        }

        return $overdue->count();
    }

    public function expire(EvReservation $reservation): EvReservation
    {
        return DB::transaction(function () use ($reservation) {
            $reservation = EvReservation::lockForUpdate()->find($reservation->id);

            if ($reservation->status->isTerminal()) {
                return $reservation;
            }

            $reservation->update([
                'status' => EvReservationStatus::Expired,
            ]);

            if ($reservation->stall_id && $reservation->stall) {
                $this->stallService->releaseStall($reservation->stall);
                TransferReservationJob::dispatch($reservation->station_id)->afterCommit();
            }

            Log::info('EV reservation expired', ['reservation_id' => $reservation->id]);

            return $reservation->fresh();
        });
    }

    /**
     * Hand a freed stall to the next queued driver. Callers that release a
     * stall dispatch TransferReservationJob, which also notifies that driver.
     */
    public function transferToNextQueued(EvChargingStation $station): ?EvReservation
    {
        $availableStall = $this->stallService->findAvailableStall($station);

        if (! $availableStall) {
            return null;
        }

        $nextInQueue = null;

        foreach (EvReservation::forStation($station->id)->queued()->lockForUpdate()->get() as $queued) {
            try {
                // The fee is collected when the stall is actually held for the driver.
                $this->fees->charge($queued);
                $nextInQueue = $queued;

                break;
            } catch (\RuntimeException $e) {
                $queued->update([
                    'status' => EvReservationStatus::Cancelled,
                    'notes' => 'Cancelled when the stall was ready: '.$e->getMessage(),
                ]);

                $this->notifySkipped($queued, $station, $e->getMessage());

                Log::info('Queued EV reservation skipped: fee not paid', ['reservation_id' => $queued->id]);
            }
        }

        if (! $nextInQueue) {
            $this->reorderQueue($station->id);

            return null;
        }

        $this->stallService->reserveStall($availableStall);

        $nextInQueue->update([
            'stall_id' => $availableStall->id,
            'status' => EvReservationStatus::Reserved,
            'queue_position' => null,
            'reserved_at' => now(),
        ]);

        $this->reorderQueue($station->id);

        Log::info('EV reservation transferred from queue', [
            'reservation_id' => $nextInQueue->id,
            'stall_id' => $availableStall->id,
        ]);

        return $nextInQueue->fresh();
    }

    private function immediateReservation(
        EvChargingStation $station,
        EvChargingStall $stall,
        User $driver,
        ?int $estimatedChargeMinutes = null,
    ): array {
        $this->stallService->reserveStall($stall);

        $feeWaived = $this->tierGate->isEvReservationFeeWaived($driver->id);

        $reservation = EvReservation::create([
            'stall_id' => $stall->id,
            'station_id' => $station->id,
            'driver_id' => $driver->id,
            'status' => EvReservationStatus::Reserved,
            'fee_amount' => $feeWaived ? 0.00 : (float) config('ev_charging.reservation_fee', 500.00),
            'fee_waived' => $feeWaived,
            'estimated_charge_minutes' => $estimatedChargeMinutes,
            'reserved_at' => now(),
        ]);

        // Throws when the driver's earnings cannot cover the fee, rolling back the hold.
        $this->fees->charge($reservation);

        Log::info('EV stall reserved immediately', [
            'reservation_id' => $reservation->id,
            'stall_id' => $stall->id,
            'station_id' => $station->id,
        ]);

        return [
            'outcome' => 'reserved',
            'reservation' => $reservation->load(['stall', 'station']),
        ];
    }

    private function queuedReservation(
        EvChargingStation $station,
        EvChargingStall $imminentStall,
        User $driver,
    ): array {
        $maxQueue = config('ev_charging.max_queue_size', 10);
        $currentQueueSize = EvReservation::forStation($station->id)->queued()->count();

        if ($currentQueueSize >= $maxQueue) {
            return $this->retryLater($station);
        }

        $queuePosition = $currentQueueSize + 1;
        $feeWaived = $this->tierGate->isEvReservationFeeWaived($driver->id);
        $this->fees->assertCanPay($driver, $feeWaived ? 0 : (int) round((float) config('ev_charging.reservation_fee', 500.00) * 100));

        $reservation = EvReservation::create([
            'station_id' => $station->id,
            'driver_id' => $driver->id,
            'status' => EvReservationStatus::Queued,
            'queue_position' => $queuePosition,
            'estimated_available_at' => $imminentStall->estimated_departure_at,
            'fee_amount' => $feeWaived ? 0.00 : (float) config('ev_charging.reservation_fee', 500.00),
            'fee_waived' => $feeWaived,
            'reserved_at' => now(),
        ]);

        Log::info('EV reservation queued', [
            'reservation_id' => $reservation->id,
            'queue_position' => $queuePosition,
            'station_id' => $station->id,
        ]);

        return [
            'outcome' => 'queued',
            'reservation' => $reservation->load('station'),
            'queue_position' => $queuePosition,
            'estimated_available_at' => $imminentStall->estimated_departure_at,
        ];
    }

    private function retryLater(EvChargingStation $station): array
    {
        $estimatedWait = $this->stallService->getEstimatedWaitMinutes($station);
        $retryDelay = config('ev_charging.retry_delay_minutes', 15);

        return [
            'outcome' => 'retry',
            'retry_after_minutes' => $retryDelay,
            'estimated_wait_minutes' => $estimatedWait,
            'message' => 'All stalls are occupied with no imminent departures. Please try again later.',
        ];
    }

    private function validateDriverEligibility(User $driver): void
    {
        if (! $driver->isDriver()) {
            throw new \RuntimeException('Only drivers can reserve EV charging stalls.');
        }

        $this->assertCanCharge($driver);

        $maxActive = config('ev_charging.max_active_reservations_per_driver', 1);

        $activeCount = EvReservation::forDriver($driver->id)
            ->active()
            ->count();

        if ($activeCount >= $maxActive) {
            throw new \RuntimeException('You already have an active reservation.');
        }
    }

    /**
     * Tell a queued driver who lost their turn because the fee could not be
     * taken, once the cancellation is committed.
     */
    private function notifySkipped(EvReservation $reservation, EvChargingStation $station, string $reason): void
    {
        DB::afterCommit(fn () => rescue(fn () => $this->pushGateway->sendToUser($reservation->driver_id, [
            'title' => 'EV Reservation Cancelled',
            'body' => "A stall at {$station->name} was ready, but your reservation was cancelled. {$reason}",
            'data' => [
                'type' => NotificationType::EvReservationCancelled->value,
                'reservation_id' => (string) $reservation->id,
                'station_name' => $station->name,
            ],
        ])));
    }

    /**
     * Charging stalls are for plug-in vehicles only (electric or plug-in hybrid).
     */
    public function assertCanCharge(User $driver): void
    {
        if (! $driver->driver?->vehicle?->fuel_type?->canCharge()) {
            throw new \RuntimeException("EV charging is only available for electric and plug-in hybrid vehicles. Set your vehicle's fuel type if it is one.");
        }
    }

    private function reorderQueue(string $stationId): void
    {
        $queuedReservations = EvReservation::forStation($stationId)
            ->queued()
            ->get();

        foreach ($queuedReservations as $index => $reservation) {
            $reservation->update(['queue_position' => $index + 1]);
        }
    }
}
