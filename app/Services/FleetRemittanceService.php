<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\FleetAgreementStatus;
use App\Enums\LedgerEntryType;
use App\Enums\NotificationType;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Exceptions\FleetAgreementFieldException;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\DailyRemittance;
use App\Models\Driver;
use App\Models\FleetAgreement;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FleetRemittanceService
{
    public function __construct(
        private LedgerService $ledgerService,
        private NotificationService $notifications,
    ) {}

    public function createAgreement(
        Driver $driver,
        Vehicle $vehicle,
        float $dailyTarget,
        float $totalVehicleCost,
        string $startDate,
        User $admin,
    ): FleetAgreement {
        $existingCurrent = FleetAgreement::where('driver_id', $driver->id)
            ->whereIn('status', [FleetAgreementStatus::Active, FleetAgreementStatus::Paused])
            ->exists();

        if ($existingCurrent) {
            throw new \DomainException('Driver already has an active or paused fleet agreement.');
        }

        if (! $vehicle->is_fleet) {
            throw new \DomainException('Vehicle must be marked as fleet before creating an agreement.');
        }

        if ($vehicle->isInMaintenance()) {
            throw new \DomainException('This car is in for maintenance. Mark it back in service before putting it on an agreement.');
        }

        // A car shared on shifts (OQ-20) cannot be bought by one driver.
        if ($vehicle->shift_mode) {
            throw new \DomainException('This car is shared on shifts. Turn shift mode off before putting it on a hire-purchase agreement.');
        }

        // One car per driver: a driver still on a shared car's timetable
        // cannot also take a car of their own.
        if ($driver->shifts()->workable()->where('ends_at', '>', now())->exists()) {
            throw new \DomainException('This driver has upcoming shifts on a shared fleet car. Cancel or reassign them before giving the driver a car of their own.');
        }

        return DB::transaction(function () use ($driver, $vehicle, $dailyTarget, $totalVehicleCost, $startDate, $admin) {
            $agreement = FleetAgreement::create([
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'daily_remittance_target' => $dailyTarget,
                'total_vehicle_cost' => $totalVehicleCost,
                'agreement_start_date' => $startDate,
                'status' => FleetAgreementStatus::Active,
                'created_by_admin_id' => $admin->id,
            ]);

            AuditLog::record($agreement, 'fleet_agreement.created', $admin, null, [
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'daily_target' => $dailyTarget,
                'total_cost' => $totalVehicleCost,
            ]);

            return $agreement;
        });
    }

    public function assignFleetVehicle(
        Driver $driver,
        Vehicle $vehicle,
        float $dailyTarget,
        float $totalVehicleCost,
        string $startDate,
        User $admin,
    ): FleetAgreement {
        return DB::transaction(function () use ($driver, $vehicle, $dailyTarget, $totalVehicleCost, $startDate, $admin) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($vehicle->id);

            if (! $vehicle->is_fleet) {
                throw new \DomainException('Vehicle must be a fleet vehicle.');
            }

            if ($vehicle->isAssigned()) {
                throw new \DomainException('Vehicle is already assigned to a driver.');
            }

            $vehicle->update(['driver_id' => $driver->id]);

            $agreement = $this->createAgreement(
                driver: $driver,
                vehicle: $vehicle,
                dailyTarget: $dailyTarget,
                totalVehicleCost: $totalVehicleCost,
                startDate: $startDate,
                admin: $admin,
            );

            AuditLog::record($vehicle, 'fleet_vehicle_assigned', $admin, null, [
                'driver_id' => $driver->id,
                'agreement_id' => $agreement->id,
            ]);

            return $agreement;
        });
    }

    /**
     * Correct an agreement's daily target, total vehicle cost or start date.
     *
     * The total can't drop to what the driver has already paid or below
     * (that would end the agreement by accident; terminate or settle it
     * instead), and the start date can't move after the first day money was
     * collected or before the day the agreement was entered.
     *
     * @param  array{daily_remittance_target?: float|int|string, total_vehicle_cost?: float|int|string, agreement_start_date?: string, reason?: string|null}  $data
     */
    public function updateAgreement(
        FleetAgreement $agreement,
        array $data,
        User $admin,
    ): FleetAgreement {
        return DB::transaction(function () use ($agreement, $data, $admin) {
            $agreement = FleetAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if (! $agreement->isActive() && ! $agreement->isPaused()) {
                throw new \DomainException('Only active or paused agreements can be changed.');
            }

            $reason = $data['reason'] ?? null;
            $changes = array_intersect_key($data, array_flip(['daily_remittance_target', 'total_vehicle_cost', 'agreement_start_date']));

            if (array_key_exists('total_vehicle_cost', $changes)
                && (float) $changes['total_vehicle_cost'] <= (float) $agreement->total_remitted) {
                throw new FleetAgreementFieldException(
                    'total_vehicle_cost',
                    'The total vehicle cost must be more than the driver has already paid (₦'.number_format((float) $agreement->total_remitted, 2).').',
                );
            }

            if (array_key_exists('agreement_start_date', $changes)) {
                $startDate = Carbon::parse($changes['agreement_start_date'])->toDateString();
                $enteredOn = $agreement->created_at->copy()->timezone('Africa/Lagos')->toDateString();
                $firstPaidDay = DailyRemittance::where('agreement_id', $agreement->id)
                    ->where('remitted_amount', '>', 0)
                    ->min('date');

                if ($startDate < $enteredOn) {
                    throw new FleetAgreementFieldException('agreement_start_date', "The start date can't be before the agreement was entered ({$enteredOn}).");
                }

                if ($firstPaidDay !== null && $startDate > Carbon::parse($firstPaidDay)->toDateString()) {
                    throw new FleetAgreementFieldException(
                        'agreement_start_date',
                        'The start date can\'t be after the first day the driver paid ('.Carbon::parse($firstPaidDay)->toDateString().').',
                    );
                }

                $changes['agreement_start_date'] = $startDate;
            }

            $oldValues = collect($agreement->only(array_keys($changes)))
                ->map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)
                ->all();

            $agreement->update($changes);

            AuditLog::record($agreement, 'fleet_agreement.updated', $admin, $oldValues, [...$changes, 'reason' => $reason]);

            return $agreement->fresh();
        });
    }

    /**
     * End an agreement and take the car back in the same transaction, so an
     * ended agreement never leaves the car with the driver. Refused while the
     * driver is on a trip in the car.
     */
    public function terminateAgreement(
        FleetAgreement $agreement,
        string $reason,
        User $admin,
        bool $releaseVehicle = true,
    ): FleetAgreement {
        return DB::transaction(function () use ($agreement, $reason, $admin, $releaseVehicle) {
            $agreement = FleetAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if ($agreement->isCompleted() || $agreement->isTerminated()) {
                throw new \DomainException('Agreement is already '.$agreement->status->value.'.');
            }

            if ($releaseVehicle) {
                $this->ensureDriverNotOnTrip($agreement);
            }

            $agreement->update([
                'status' => FleetAgreementStatus::Terminated,
                'terminated_reason' => $reason,
                'terminated_at' => now(),
            ]);

            AuditLog::record($agreement, 'fleet_agreement.terminated', $admin, null, [
                'reason' => $reason,
            ]);

            if ($releaseVehicle) {
                $this->releaseVehicle($agreement, $admin);
            }

            return $agreement->fresh();
        });
    }

    public function pauseAgreement(FleetAgreement $agreement, User $admin, ?string $reason = null): FleetAgreement
    {
        $agreement = DB::transaction(function () use ($agreement, $admin, $reason) {
            $agreement = FleetAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if (! $agreement->isActive()) {
                throw new \DomainException('Only active agreements can be paused.');
            }

            $agreement->update([
                'status' => FleetAgreementStatus::Paused,
                'paused_at' => now(),
                'paused_reason' => $reason,
            ]);

            AuditLog::record($agreement, 'fleet_agreement.paused', $admin, null, ['reason' => $reason]);

            return $agreement->fresh();
        });

        $this->notifyDriver($agreement, NotificationType::FleetAgreementPaused, 'E-tiGo has paused your fleet agreement. No remittance is due until it resumes.');

        return $agreement;
    }

    public function resumeAgreement(FleetAgreement $agreement, User $admin): FleetAgreement
    {
        $agreement = DB::transaction(function () use ($agreement, $admin) {
            $agreement = FleetAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if (! $agreement->isPaused()) {
                throw new \DomainException('Only paused agreements can be resumed.');
            }

            $pausedReason = $agreement->paused_reason;

            $agreement->update([
                'status' => FleetAgreementStatus::Active,
                'paused_at' => null,
                'paused_reason' => null,
            ]);

            AuditLog::record($agreement, 'fleet_agreement.resumed', $admin, ['paused_reason' => $pausedReason]);

            return $agreement->fresh();
        });

        $this->notifyDriver($agreement, NotificationType::FleetAgreementResumed, 'Your fleet agreement has resumed. Your daily remittance target applies again.');

        return $agreement;
    }

    public function getOrCreateTodayRemittance(FleetAgreement $agreement): DailyRemittance
    {
        return DailyRemittance::firstOrCreate(
            [
                'agreement_id' => $agreement->id,
                'date' => $this->currentBusinessDate(),
            ],
            [
                'driver_id' => $agreement->driver_id,
                'target_amount' => $agreement->daily_remittance_target,
            ],
        );
    }

    private function currentBusinessDate(): string
    {
        $now = Carbon::now('Africa/Lagos');
        $resetTime = config('fleet.daily_reset_time', '04:00');
        $reset = $now->copy()->setTimeFromTimeString($resetTime);

        return $now->lt($reset)
            ? $now->subDay()->toDateString()
            : $now->toDateString();
    }

    /**
     * Allocate a ride fare between E-tiGo (remittance) and the driver.
     *
     * When the ride is given, the allocation is recorded at most once per ride
     * and the remittance share is moved out of the driver's earnings in the
     * ledger, so a fleet driver can only withdraw what they earned after the
     * daily target was met.
     */
    public function recordRideRemittance(
        FleetAgreement $agreement,
        float $fareAmount,
        ?Ride $ride = null,
    ): DailyRemittance {
        if (! $agreement->isActive()) {
            throw new \DomainException('Cannot record remittance on a non-active agreement.');
        }

        return DB::transaction(function () use ($agreement, $fareAmount, $ride) {
            if ($ride) {
                $ride = Ride::lockForUpdate()->findOrFail($ride->id);
                $recorded = $ride->pricing_snapshot['fleet_remittance'] ?? null;

                if ($recorded !== null) {
                    return DailyRemittance::where('agreement_id', $recorded['agreement_id'])
                        ->where('date', $recorded['date'])
                        ->firstOrFail();
                }
            }

            $agreement = FleetAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            if (! $agreement->isActive()) {
                throw new \DomainException('Cannot record remittance on a non-active agreement.');
            }

            $this->getOrCreateTodayRemittance($agreement);
            $remittance = DailyRemittance::where('agreement_id', $agreement->id)
                ->where('date', $this->currentBusinessDate())
                ->lockForUpdate()
                ->firstOrFail();

            $remainingTarget = max(0, $remittance->target_amount - $remittance->remitted_amount);
            $remittanceShare = min($fareAmount, $remainingTarget, $agreement->remainingAmount());
            $driverShare = $fareAmount - $remittanceShare;

            if ($ride) {
                $this->moveRemittanceShareInLedger($ride, $agreement, $remittanceShare);

                $ride->update([
                    'pricing_snapshot' => [
                        ...($ride->pricing_snapshot ?? []),
                        'fleet_remittance' => [
                            'agreement_id' => $agreement->id,
                            'date' => $remittance->date->toDateString(),
                            'remittance_share' => round($remittanceShare, 2),
                            'driver_share' => round($driverShare, 2),
                        ],
                    ],
                ]);
            }

            $remittance->increment('ride_count');
            $remittance->increment('total_fares', $fareAmount);
            $remittance->increment('remitted_amount', $remittanceShare);
            $remittance->increment('driver_earnings', $driverShare);

            $remittance->refresh();
            $remittance->shortfall_amount = max(0, $remittance->target_amount - $remittance->remitted_amount);

            if (! $remittance->hasMetTarget() && $remittance->remitted_amount >= $remittance->target_amount) {
                $remittance->target_met_at = now();
            }

            $remittance->save();

            $agreement->increment('total_remitted', $remittanceShare);

            if ($agreement->fresh()->total_remitted >= $agreement->total_vehicle_cost) {
                $this->completeAgreement($agreement);
            }

            return $remittance->fresh();
        });
    }

    /**
     * Fleet drivers pay no commission, so their fares are credited to them in
     * full; the remittance share is then moved to E-tiGo. Cash fares are
     * already in the driver's hands, so for them the share becomes a balance
     * owed on the available account (like cash commission).
     */
    private function moveRemittanceShareInLedger(Ride $ride, FleetAgreement $agreement, float $remittanceShare): void
    {
        $shareKobo = (int) round($remittanceShare * 100);

        if ($shareKobo <= 0) {
            return;
        }

        $accountType = $ride->payment_method === PaymentMethod::Cash
            || AppSetting::wallet('settlement_delay') === 'instant'
                ? AccountType::DriverEarningsAvailable
                : AccountType::DriverEarningsPending;

        $driverAccount = $this->ledgerService->findOrCreateAccount(
            Driver::class,
            $agreement->driver_id,
            $accountType,
        );
        $platformAccount = $this->ledgerService->systemAccount(AccountType::PlatformCommission);

        $this->ledgerService->postJournal([
            ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $shareKobo],
            ['account_id' => $platformAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $shareKobo],
        ], [
            'description' => "Fleet remittance for ride {$ride->id}",
            'idempotency_key' => "fleet-remittance-{$ride->id}",
            'metadata' => [
                'ride_id' => $ride->id,
                'agreement_id' => $agreement->id,
                'remittance_kobo' => $shareKobo,
            ],
        ]);
    }

    public function settleDay(DailyRemittance $remittance): DailyRemittance
    {
        return DB::transaction(function () use ($remittance) {
            $remittance = DailyRemittance::whereKey($remittance->id)->lockForUpdate()->firstOrFail();

            if ($remittance->settled) {
                return $remittance;
            }

            $remittance->update(['settled' => true]);

            $agreement = $remittance->agreement;

            if ($remittance->isExcused() || $agreement->status === FleetAgreementStatus::Paused) {
                return $remittance->fresh();
            }

            if ($remittance->shortfall() > 0) {
                $agreement->increment('shortfall_streak_days');
            } else {
                $agreement->update(['shortfall_streak_days' => 0]);
            }

            return $remittance->fresh();
        });
    }

    public function excuseDay(DailyRemittance $remittance, string $reason, User $admin): DailyRemittance
    {
        return DB::transaction(function () use ($remittance, $reason, $admin) {
            $remittance = DailyRemittance::whereKey($remittance->id)->lockForUpdate()->firstOrFail();

            if ($remittance->isExcused()) {
                throw new \DomainException('This remittance day is already excused.');
            }

            $remittance->update(['excused_reason' => $reason]);

            AuditLog::record($remittance->agreement, 'remittance_day_excused', $admin, null, [
                'date' => $remittance->date->toDateString(),
                'reason' => $reason,
            ]);

            if ($remittance->settled && $remittance->shortfall() > 0) {
                $agreement = FleetAgreement::whereKey($remittance->agreement_id)->lockForUpdate()->firstOrFail();
                if ($agreement->shortfall_streak_days > 0) {
                    $agreement->decrement('shortfall_streak_days');
                }
            }

            return $remittance->fresh();
        });
    }

    /**
     * Take back an excuse given by mistake. A settled day that fell short
     * counts towards the shortfall streak again, as it did before.
     */
    public function unexcuseDay(DailyRemittance $remittance, string $reason, User $admin): DailyRemittance
    {
        return DB::transaction(function () use ($remittance, $reason, $admin) {
            $remittance = DailyRemittance::whereKey($remittance->id)->lockForUpdate()->firstOrFail();

            if (! $remittance->isExcused()) {
                throw new \DomainException('This remittance day is not excused.');
            }

            $agreement = FleetAgreement::whereKey($remittance->agreement_id)->lockForUpdate()->firstOrFail();
            $excusedReason = $remittance->excused_reason;

            $remittance->update(['excused_reason' => null]);

            AuditLog::record($agreement, 'remittance_day_excuse_withdrawn', $admin, ['excused_reason' => $excusedReason], [
                'date' => $remittance->date->toDateString(),
                'reason' => $reason,
            ]);

            if ($remittance->settled && $remittance->shortfall() > 0 && $agreement->isActive()) {
                $agreement->increment('shortfall_streak_days');
            }

            return $remittance->fresh();
        });
    }

    /**
     * @throws \DomainException when the driver is on a trip
     */
    private function ensureDriverNotOnTrip(FleetAgreement $agreement): void
    {
        $userId = Driver::whereKey($agreement->driver_id)->value('user_id');

        if ($userId && Ride::where('driver_id', $userId)->whereIn('status', RideStatus::driverOnTripStatuses())->exists()) {
            throw new \DomainException('The driver is on a trip in this car. End the agreement once the trip is over.');
        }
    }

    /**
     * Take the agreement's car back from the driver (when they still have it)
     * and take them offline: without a car they can't take rides.
     */
    private function releaseVehicle(FleetAgreement $agreement, ?User $admin): void
    {
        $vehicle = Vehicle::whereKey($agreement->vehicle_id)->lockForUpdate()->first();

        if ($vehicle === null || $vehicle->driver_id !== $agreement->driver_id) {
            return;
        }

        $vehicle->update(['driver_id' => null]);

        AuditLog::record($vehicle, 'fleet_vehicle_unassigned', $admin, ['driver_id' => $agreement->driver_id], [
            'agreement_id' => $agreement->id,
            'reason' => 'agreement_ended',
        ]);

        $driver = Driver::whereKey($agreement->driver_id)->lockForUpdate()->first();

        if ($driver?->is_online) {
            $driver->update(['is_online' => false]);

            DB::afterCommit(function () use ($driver) {
                rescue(fn () => app(DriverLocationService::class)->removeDriver($driver->id, $driver->user_id), report: false);
            });
        }
    }

    private function notifyDriver(FleetAgreement $agreement, NotificationType $type, string $body): void
    {
        $userId = Driver::whereKey($agreement->driver_id)->value('user_id');

        if (! $userId) {
            return;
        }

        rescue(fn () => $this->notifications->send(
            userId: $userId,
            type: $type,
            title: $type->label(),
            body: $body,
            data: ['fleet_agreement_id' => (string) $agreement->id],
        ));
    }

    private function completeAgreement(FleetAgreement $agreement): void
    {
        $agreement->update([
            'status' => FleetAgreementStatus::Completed,
            'completed_at' => now(),
        ]);

        AuditLog::record($agreement, 'fleet_agreement.completed', null, null, [
            'total_remitted' => $agreement->total_remitted,
            'total_vehicle_cost' => $agreement->total_vehicle_cost,
        ]);
    }

    public function swapVehicle(
        FleetAgreement $currentAgreement,
        Vehicle $newVehicle,
        string $reason,
        bool $carryOverRemitted,
        ?float $newDailyTarget,
        ?float $newTotalCost,
        User $admin,
    ): FleetAgreement {
        return DB::transaction(function () use (
            $currentAgreement, $newVehicle, $reason,
            $carryOverRemitted, $newDailyTarget, $newTotalCost, $admin,
        ) {
            $newVehicle = Vehicle::lockForUpdate()->findOrFail($newVehicle->id);

            if (! $newVehicle->is_fleet) {
                throw new \DomainException('Replacement vehicle must be a fleet vehicle.');
            }

            if ($newVehicle->isAssigned()) {
                throw new \DomainException('Replacement vehicle is already assigned.');
            }

            if ($newVehicle->isInMaintenance()) {
                throw new \DomainException('Replacement vehicle is in for maintenance.');
            }

            $this->ensureDriverNotOnTrip($currentAgreement);

            $driver = $currentAgreement->driver;
            $oldVehicle = $currentAgreement->vehicle;

            $this->terminateAgreement($currentAgreement, "Vehicle swap: {$reason}", $admin, releaseVehicle: false);

            $oldVehicle->update(['driver_id' => null]);
            $newVehicle->update(['driver_id' => $driver->id]);

            $newAgreement = $this->createAgreement(
                driver: $driver,
                vehicle: $newVehicle,
                dailyTarget: $newDailyTarget ?? $currentAgreement->daily_remittance_target,
                totalVehicleCost: $newTotalCost ?? $currentAgreement->total_vehicle_cost,
                startDate: now()->toDateString(),
                admin: $admin,
            );

            if ($carryOverRemitted) {
                $newAgreement->update(['total_remitted' => $currentAgreement->total_remitted]);

                if ($currentAgreement->total_remitted >= $newAgreement->total_vehicle_cost) {
                    $this->completeAgreement($newAgreement->fresh());
                    $newAgreement->refresh();
                }
            }

            AuditLog::record($newAgreement, 'fleet_vehicle_swapped', $admin, null, [
                'previous_agreement_id' => $currentAgreement->id,
                'previous_vehicle_id' => $oldVehicle->id,
                'new_vehicle_id' => $newVehicle->id,
                'reason' => $reason,
                'remitted_carried_over' => $carryOverRemitted,
                'carried_amount' => $carryOverRemitted ? $currentAgreement->total_remitted : 0,
            ]);

            return $newAgreement->fresh();
        });
    }

    public function terminateWithSettlement(
        FleetAgreement $agreement,
        string $reason,
        User $admin,
        ?string $vehicleReturnStatus = null,
        float $outstandingAmount = 0,
        float $settlementAmount = 0,
        ?string $settlementNotes = null,
    ): FleetAgreement {
        return DB::transaction(function () use (
            $agreement, $reason, $admin,
            $vehicleReturnStatus, $outstandingAmount, $settlementAmount, $settlementNotes,
        ) {
            $agreement = FleetAgreement::whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if ($agreement->isCompleted() || $agreement->isTerminated()) {
                throw new \DomainException('Agreement is already '.$agreement->status->value.'.');
            }

            $this->ensureDriverNotOnTrip($agreement);

            $agreement->update([
                'status' => FleetAgreementStatus::Terminated,
                'terminated_reason' => $reason,
                'terminated_at' => now(),
                'vehicle_return_status' => $vehicleReturnStatus,
                'outstanding_amount' => $outstandingAmount,
                'settlement_amount' => $settlementAmount,
                'settlement_notes' => $settlementNotes,
                'settled_at' => $settlementAmount > 0 ? now() : null,
            ]);

            AuditLog::record($agreement, 'fleet_agreement.terminated_with_settlement', $admin, null, [
                'reason' => $reason,
                'vehicle_return_status' => $vehicleReturnStatus,
                'outstanding_amount' => $outstandingAmount,
                'settlement_amount' => $settlementAmount,
            ]);

            // The car comes off the driver's account whether or not it has
            // been handed back yet; vehicle_return_status tracks the handover.
            $this->releaseVehicle($agreement, $admin);

            return $agreement->fresh();
        });
    }

    public function getDriverTodayRemittance(Driver $driver): ?DailyRemittance
    {
        $agreement = $driver->activeFleetAgreement;

        if (! $agreement) {
            return null;
        }

        return DailyRemittance::where('agreement_id', $agreement->id)
            ->where('date', $this->currentBusinessDate())
            ->first();
    }

    public function getDriverRemittanceHistory(Driver $driver, int $perPage = 20)
    {
        return DailyRemittance::where('driver_id', $driver->id)
            ->orderByDesc('date')
            ->paginate($perPage);
    }
}
