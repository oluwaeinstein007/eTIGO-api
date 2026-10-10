<?php

namespace App\Services;

use App\Enums\FleetAgreementStatus;
use App\Models\AuditLog;
use App\Models\DailyRemittance;
use App\Models\Driver;
use App\Models\FleetAgreement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FleetRemittanceService
{
    public function createAgreement(
        Driver $driver,
        Vehicle $vehicle,
        float $dailyTarget,
        float $totalVehicleCost,
        string $startDate,
        User $admin,
    ): FleetAgreement {
        $existingActive = FleetAgreement::where('driver_id', $driver->id)
            ->active()
            ->exists();

        if ($existingActive) {
            throw new \DomainException('Driver already has an active fleet agreement.');
        }

        if (! $vehicle->is_fleet) {
            throw new \DomainException('Vehicle must be marked as fleet before creating an agreement.');
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

    public function updateAgreement(
        FleetAgreement $agreement,
        array $data,
        User $admin,
    ): FleetAgreement {
        if (! $agreement->isActive()) {
            throw new \DomainException('Only active agreements can be updated.');
        }

        return DB::transaction(function () use ($agreement, $data, $admin) {
            $oldValues = $agreement->only(array_keys($data));
            $agreement->update($data);

            AuditLog::record($agreement, 'fleet_agreement.updated', $admin, $oldValues, $data);

            return $agreement->fresh();
        });
    }

    public function terminateAgreement(
        FleetAgreement $agreement,
        string $reason,
        User $admin,
    ): FleetAgreement {
        if ($agreement->isCompleted() || $agreement->isTerminated()) {
            throw new \DomainException('Agreement is already '.$agreement->status->value.'.');
        }

        return DB::transaction(function () use ($agreement, $reason, $admin) {
            $agreement->update([
                'status' => FleetAgreementStatus::Terminated,
                'terminated_reason' => $reason,
                'terminated_at' => now(),
            ]);

            AuditLog::record($agreement, 'fleet_agreement.terminated', $admin, null, [
                'reason' => $reason,
            ]);

            return $agreement->fresh();
        });
    }

    public function pauseAgreement(FleetAgreement $agreement, User $admin): FleetAgreement
    {
        if (! $agreement->isActive()) {
            throw new \DomainException('Only active agreements can be paused.');
        }

        return DB::transaction(function () use ($agreement, $admin) {
            $agreement->update([
                'status' => FleetAgreementStatus::Paused,
                'paused_at' => now(),
            ]);

            AuditLog::record($agreement, 'fleet_agreement.paused', $admin);

            return $agreement->fresh();
        });
    }

    public function resumeAgreement(FleetAgreement $agreement, User $admin): FleetAgreement
    {
        if ($agreement->status !== FleetAgreementStatus::Paused) {
            throw new \DomainException('Only paused agreements can be resumed.');
        }

        return DB::transaction(function () use ($agreement, $admin) {
            $agreement->update([
                'status' => FleetAgreementStatus::Active,
                'paused_at' => null,
            ]);

            AuditLog::record($agreement, 'fleet_agreement.resumed', $admin);

            return $agreement->fresh();
        });
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

    public function recordRideRemittance(
        FleetAgreement $agreement,
        float $fareAmount,
    ): DailyRemittance {
        if (! $agreement->isActive()) {
            throw new \DomainException('Cannot record remittance on a non-active agreement.');
        }

        return DB::transaction(function () use ($agreement, $fareAmount) {
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

    public function settleDay(DailyRemittance $remittance): DailyRemittance
    {
        return DB::transaction(function () use ($remittance) {
            $remittance = DailyRemittance::whereKey($remittance->id)->lockForUpdate()->firstOrFail();

            if ($remittance->settled) {
                return $remittance;
            }

            $remittance->update(['settled' => true]);

            $agreement = $remittance->agreement;

            if ($remittance->isExcused()) {
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

            $driver = $currentAgreement->driver;
            $oldVehicle = $currentAgreement->vehicle;

            $this->terminateAgreement($currentAgreement, "Vehicle swap: {$reason}", $admin);

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
        if ($agreement->isCompleted() || $agreement->isTerminated()) {
            throw new \DomainException('Agreement is already '.$agreement->status->value.'.');
        }

        return DB::transaction(function () use (
            $agreement, $reason, $admin,
            $vehicleReturnStatus, $outstandingAmount, $settlementAmount, $settlementNotes,
        ) {
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
