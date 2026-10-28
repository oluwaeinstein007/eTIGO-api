<?php

namespace App\Services;

use App\Contracts\PushNotificationGateway;
use App\Enums\EvReservationStatus;
use App\Enums\NotificationType;
use App\Jobs\TransferReservationJob;
use App\Models\AppSetting;
use App\Models\AuditLog;
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
        private readonly EvReservationFeeService $fees,
        private readonly PushNotificationGateway $pushGateway,
        private readonly NotificationService $notifications,
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
                'estimated_charge_minutes' => $estimatedChargeMinutes ?? $reservation->estimated_charge_minutes,
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
     * Operations cancel a driver's open request or reservation (a broken
     * charger, a closed site, a driver who will not show up). The stall goes
     * to the next driver in line; the fee is refunded only when asked.
     *
     * @return array{reservation: EvReservation, fee_refunded: bool}
     */
    public function cancelByOperations(EvReservation $reservation, User $admin, string $reason, bool $refundFee): array
    {
        $refunded = false;

        $reservation = DB::transaction(function () use ($reservation, $admin, $reason, $refundFee, &$refunded) {
            $reservation = EvReservation::lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->status->isTerminal()) {
                throw new \RuntimeException('This reservation has already ended.');
            }

            $wasQueued = $reservation->status === EvReservationStatus::Queued;

            $reservation->update([
                'status' => EvReservationStatus::Cancelled,
                'queue_position' => null,
                'ended_by_admin_id' => $admin->id,
                'ended_at' => now(),
                'end_reason' => $reason,
                'updated_at' => now(),
            ]);

            $refunded = $refundFee && $this->fees->refund($reservation);
            $this->releaseHeldStall($reservation);

            if ($wasQueued) {
                $this->reorderQueue($reservation->station_id);
            }

            AuditLog::record($reservation, 'ev_reservation.cancelled_by_operations', $admin, null, [
                'reason' => $reason,
                'fee_refunded' => $refunded,
            ]);

            return $reservation->fresh(['station', 'stall']);
        });

        $fee = $refunded ? ' Your ₦'.number_format((float) $reservation->fee_amount, 2).' reservation fee has been refunded.' : '';

        $this->notifyDriver(
            $reservation,
            NotificationType::EvReservationCancelled,
            'Your charging reservation at '.($reservation->station?->name ?? 'the station').' was cancelled by E-tiGo Operations: '.rtrim($reason, '. ').'.'.$fee,
        );

        return ['reservation' => $reservation, 'fee_refunded' => $refunded];
    }

    /**
     * Operations end a session the driver did not finish in the app (they
     * drove off, or their phone died). The stall goes to the next in line.
     * The fee is kept unless operations choose to refund it.
     */
    public function endByOperations(EvReservation $reservation, User $admin, ?string $reason, bool $refundFee = false): EvReservation
    {
        $refunded = false;

        $reservation = DB::transaction(function () use ($reservation, $admin, $reason, $refundFee, &$refunded) {
            $reservation = EvReservation::lockForUpdate()->findOrFail($reservation->id);

            if (! in_array($reservation->status, [EvReservationStatus::Active, EvReservationStatus::Reserved], true)) {
                throw new \RuntimeException('Only a reserved stall or a charging session can be ended. Cancel requests and queued drivers instead.');
            }

            $reservation->update([
                'status' => EvReservationStatus::Completed,
                'completed_at' => now(),
                'ended_by_admin_id' => $admin->id,
                'ended_at' => now(),
                'end_reason' => $reason,
                'updated_at' => now(),
            ]);

            $refunded = $refundFee && $this->fees->refund($reservation);
            $this->releaseHeldStall($reservation);

            AuditLog::record($reservation, 'ev_reservation.ended_by_operations', $admin, null, ['reason' => $reason, 'fee_refunded' => $refunded]);

            return $reservation->fresh(['station', 'stall']);
        });

        $this->notifyDriver(
            $reservation,
            NotificationType::EvReservationEnded,
            'Your charging session at '.($reservation->station?->name ?? 'the station').' was ended by E-tiGo Operations'
                .($reason ? ': '.rtrim($reason, '. ').'.' : '.')
                .($refunded ? ' Your ₦'.number_format((float) $reservation->fee_amount, 2).' reservation fee has been refunded.' : ''),
        );

        return $reservation;
    }

    /**
     * Release reserved stalls whose driver never arrived within the expiry window.
     *
     * @return int Number of reservations expired
     */
    public function expireOverdueReservations(): int
    {
        $overdue = EvReservation::where('status', EvReservationStatus::Reserved)
            ->where('reserved_at', '<=', now()->subMinutes(AppSetting::evCharging()['reservation_expiry_minutes']))
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

        $reservation = EvReservation::create([
            'stall_id' => $stall->id,
            'station_id' => $station->id,
            'driver_id' => $driver->id,
            'status' => EvReservationStatus::Reserved,
            ...$this->fees->termsFor($driver->id),
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
        $maxQueue = AppSetting::evCharging()['max_queue_size'];
        $currentQueueSize = EvReservation::forStation($station->id)->queued()->count();

        if ($currentQueueSize >= $maxQueue) {
            return $this->retryLater($station);
        }

        $queuePosition = $currentQueueSize + 1;
        $terms = $this->fees->termsFor($driver->id);
        $this->fees->assertCanPay($driver, (int) round($terms['fee_amount'] * 100));

        $reservation = EvReservation::create([
            'station_id' => $station->id,
            'driver_id' => $driver->id,
            'status' => EvReservationStatus::Queued,
            'queue_position' => $queuePosition,
            'estimated_available_at' => $imminentStall->estimated_departure_at,
            ...$terms,
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
        $retryDelay = AppSetting::evCharging()['retry_delay_minutes'];

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

        $maxActive = AppSetting::evCharging()['max_active_reservations_per_driver'];

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

    /**
     * Free the stall a reservation was holding and offer it to the queue.
     * Must run inside the reservation's transaction.
     */
    private function releaseHeldStall(EvReservation $reservation): void
    {
        if ($reservation->stall_id && $reservation->stall) {
            $this->stallService->releaseStall($reservation->stall);
            TransferReservationJob::dispatch($reservation->station_id)->afterCommit();
        }
    }

    private function notifyDriver(EvReservation $reservation, NotificationType $type, string $body): void
    {
        rescue(fn () => $this->notifications->send(
            userId: $reservation->driver_id,
            type: $type,
            title: $type->label(),
            body: $body,
            data: [
                'reservation_id' => (string) $reservation->id,
                'station_name' => (string) ($reservation->station?->name ?? ''),
            ],
        ));
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
