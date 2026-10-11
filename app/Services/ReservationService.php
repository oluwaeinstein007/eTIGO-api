<?php

namespace App\Services;

use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Models\EvChargingStation;
use App\Models\EvChargingStall;
use App\Models\EvReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReservationService
{
    public function __construct(
        private readonly StallAvailabilityService $stallService,
        private readonly TierGateService $tierGate,
    ) {}

    /**
     * Three outcomes:
     * (a) Stall available → immediate lock
     * (b) Occupied but departure imminent → queue hold
     * (c) Wait > threshold → return retry delay
     */
    public function reserve(EvChargingStation $station, User $driver): array
    {
        $this->validateDriverEligibility($driver);

        return DB::transaction(function () use ($station, $driver) {
            $station->lockForUpdate();

            $availableStall = $this->stallService->findAvailableStall($station);

            if ($availableStall) {
                return $this->immediateReservation($station, $availableStall, $driver);
            }

            $imminentStall = $this->stallService->findImminentDepartureStall($station);

            if ($imminentStall) {
                return $this->queuedReservation($station, $imminentStall, $driver);
            }

            return $this->retryLater($station);
        });
    }

    public function activate(EvReservation $reservation): EvReservation
    {
        if ($reservation->status !== EvReservationStatus::Reserved) {
            throw new \RuntimeException('Only reserved reservations can be activated.');
        }

        $reservation->update([
            'status' => EvReservationStatus::Active,
            'activated_at' => now(),
        ]);

        if ($reservation->stall) {
            $this->stallService->occupyStall($reservation->stall, $reservation->driver_id);
        }

        Log::info('EV reservation activated', [
            'reservation_id' => $reservation->id,
            'stall_id' => $reservation->stall_id,
        ]);

        return $reservation->fresh();
    }

    public function complete(EvReservation $reservation): EvReservation
    {
        if (! in_array($reservation->status, [EvReservationStatus::Active, EvReservationStatus::Reserved])) {
            throw new \RuntimeException('Only active or reserved reservations can be completed.');
        }

        $reservation->update([
            'status' => EvReservationStatus::Completed,
            'completed_at' => now(),
        ]);

        if ($reservation->stall_id) {
            $this->stallService->releaseStall($reservation->stall);
            $this->transferToNextQueued($reservation->station);
        }

        Log::info('EV reservation completed', ['reservation_id' => $reservation->id]);

        return $reservation->fresh();
    }

    public function cancel(EvReservation $reservation): EvReservation
    {
        if ($reservation->status->isTerminal()) {
            throw new \RuntimeException('Cannot cancel a terminal reservation.');
        }

        $wasQueued = $reservation->status === EvReservationStatus::Queued;
        $stationId = $reservation->station_id;

        $reservation->update([
            'status' => EvReservationStatus::Cancelled,
        ]);

        if ($reservation->stall_id && $reservation->stall?->status === EvStallStatus::Reserved) {
            $this->stallService->releaseStall($reservation->stall);
        }

        if ($wasQueued) {
            $this->reorderQueue($stationId);
        }

        Log::info('EV reservation cancelled', ['reservation_id' => $reservation->id]);

        return $reservation->fresh();
    }

    public function expire(EvReservation $reservation): EvReservation
    {
        if ($reservation->status->isTerminal()) {
            return $reservation;
        }

        $reservation->update([
            'status' => EvReservationStatus::Expired,
        ]);

        if ($reservation->stall_id && $reservation->stall?->status === EvStallStatus::Reserved) {
            $this->stallService->releaseStall($reservation->stall);
            $this->transferToNextQueued($reservation->station);
        }

        Log::info('EV reservation expired', ['reservation_id' => $reservation->id]);

        return $reservation->fresh();
    }

    public function transferToNextQueued(EvChargingStation $station): void
    {
        $nextInQueue = EvReservation::forStation($station->id)
            ->queued()
            ->lockForUpdate()
            ->first();

        if (! $nextInQueue) {
            return;
        }

        $availableStall = $this->stallService->findAvailableStall($station);

        if (! $availableStall) {
            return;
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
    }

    private function immediateReservation(
        EvChargingStation $station,
        EvChargingStall $stall,
        User $driver,
    ): array {
        $this->stallService->reserveStall($stall);

        $reservation = EvReservation::create([
            'stall_id' => $stall->id,
            'station_id' => $station->id,
            'driver_id' => $driver->id,
            'status' => EvReservationStatus::Reserved,
            'fee_amount' => $this->calculateFee($driver),
            'fee_waived' => $this->tierGate->isEvReservationFeeWaived($driver->id),
            'reserved_at' => now(),
        ]);

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

        $reservation = EvReservation::create([
            'station_id' => $station->id,
            'driver_id' => $driver->id,
            'status' => EvReservationStatus::Queued,
            'queue_position' => $queuePosition,
            'estimated_available_at' => $imminentStall->estimated_departure_at,
            'fee_amount' => $this->calculateFee($driver),
            'fee_waived' => $this->tierGate->isEvReservationFeeWaived($driver->id),
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

        $maxActive = config('ev_charging.max_active_reservations_per_driver', 1);

        $activeCount = EvReservation::forDriver($driver->id)
            ->active()
            ->count();

        if ($activeCount >= $maxActive) {
            throw new \RuntimeException('You already have an active reservation.');
        }
    }

    private function calculateFee(User $driver): float
    {
        if ($this->tierGate->isEvReservationFeeWaived($driver->id)) {
            return 0.00;
        }

        return (float) config('ev_charging.reservation_fee', 500.00);
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
