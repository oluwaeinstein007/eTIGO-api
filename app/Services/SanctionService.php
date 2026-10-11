<?php

namespace App\Services;

use App\Contracts\PushNotificationGateway;
use App\Enums\DisputeOutcome;
use App\Enums\DriverStatus;
use App\Enums\SanctionTier;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\OfflineTripFlag;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SanctionService
{
    public function __construct(
        private readonly PushNotificationGateway $pushGateway,
    ) {}

    public function applyForRide(Ride $ride, array $detectionData): OfflineTripFlag
    {
        $driverUserId = $ride->driver_id;
        $tier = $this->determineTier($driverUserId);

        return DB::transaction(function () use ($ride, $driverUserId, $tier, $detectionData) {
            $flag = OfflineTripFlag::create([
                'ride_id' => $ride->id,
                'driver_id' => $driverUserId,
                'passenger_id' => $ride->passenger_id,
                'detection_data' => $detectionData,
                'sanction_tier' => $tier,
                'sanction_action' => $tier->action(),
                'flagged_at' => now(),
            ]);

            $this->enforceSanction($driverUserId, $tier);

            AuditLog::record($flag, 'offline_trip_flagged', null, null, [
                'sanction_tier' => $tier->value,
                'sanction_action' => $tier->action(),
                'ride_id' => $ride->id,
                'driver_id' => $driverUserId,
            ]);

            Log::warning('Offline trip flagged', [
                'flag_id' => $flag->id,
                'ride_id' => $ride->id,
                'driver_id' => $driverUserId,
                'sanction_tier' => $tier->value,
            ]);

            return $flag;
        });
    }

    public function determineTier(string $driverUserId): SanctionTier
    {
        $recentFlagCount = OfflineTripFlag::forDriver($driverUserId)
            ->withinLookback()
            ->where(function ($q) {
                $q->where('is_disputed', false)
                    ->orWhere('dispute_outcome', '!=', DisputeOutcome::Overturned);
            })
            ->count();

        return match (true) {
            $recentFlagCount >= 2 => SanctionTier::Deactivation,
            $recentFlagCount >= 1 => SanctionTier::Suspension,
            default => SanctionTier::Warning,
        };
    }

    public function enforceSanction(string $driverUserId, SanctionTier $tier): void
    {
        $driver = Driver::where('user_id', $driverUserId)->first();
        if (! $driver) {
            return;
        }

        match ($tier) {
            SanctionTier::Warning => $this->issueWarning($driver),
            SanctionTier::Suspension => $this->suspendDriver($driver),
            SanctionTier::Deactivation => $this->deactivateDriver($driver),
        };
    }

    private function issueWarning(Driver $driver): void
    {
        $this->pushGateway->sendToUser($driver->user_id, [
            'title' => 'Compliance Warning',
            'body' => 'Our system detected potential off-app trip activity. Continued violations may result in suspension.',
            'data' => ['type' => 'compliance_warning'],
        ]);
    }

    private function suspendDriver(Driver $driver): void
    {
        $hours = config('offline_detection.suspension_hours', 48);

        $driver->update([
            'is_online' => false,
            'status' => DriverStatus::Suspended,
            'suspended_at' => now(),
        ]);

        $this->pushGateway->sendToUser($driver->user_id, [
            'title' => 'Account Suspended',
            'body' => "Your account has been suspended for {$hours} hours due to repeated offline trip violations.",
            'data' => ['type' => 'compliance_warning', 'suspension_hours' => $hours],
        ]);
    }

    private function deactivateDriver(Driver $driver): void
    {
        $driver->update([
            'is_online' => false,
            'status' => DriverStatus::Suspended,
            'suspended_at' => now(),
        ]);

        $this->pushGateway->sendToUser($driver->user_id, [
            'title' => 'Account Deactivated',
            'body' => 'Your account has been permanently deactivated due to repeated offline trip violations. You may dispute this decision.',
            'data' => ['type' => 'compliance_warning', 'permanent' => true],
        ]);
    }

    public function reverseSanction(OfflineTripFlag $flag): void
    {
        $driver = Driver::where('user_id', $flag->driver_id)->first();
        if (! $driver) {
            return;
        }

        if ($driver->isSuspended() && $flag->sanction_tier !== SanctionTier::Warning) {
            $remainingActiveFlags = OfflineTripFlag::forDriver($flag->driver_id)
                ->withinLookback()
                ->where('id', '!=', $flag->id)
                ->where(function ($q) {
                    $q->where('is_disputed', false)
                        ->orWhere('dispute_outcome', '!=', DisputeOutcome::Overturned);
                })
                ->count();

            if ($remainingActiveFlags === 0) {
                $driver->update([
                    'status' => DriverStatus::Approved,
                    'suspended_at' => null,
                ]);

                $this->pushGateway->sendToUser($driver->user_id, [
                    'title' => 'Account Reinstated',
                    'body' => 'Your offline trip flag has been overturned and your account has been reinstated.',
                    'data' => ['type' => 'compliance_warning', 'reinstated' => true],
                ]);
            }
        }
    }
}
