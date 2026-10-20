<?php

namespace App\Services;

use App\Contracts\PushNotificationGateway;
use App\Enums\DriverStatus;
use App\Enums\SanctionTier;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\OfflineTripFlag;
use App\Models\Ride;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SanctionService
{
    /**
     * Tags a suspension imposed automatically by the L2 sanction so it can be
     * lifted when it expires without touching suspensions made by admins.
     */
    public const AUTOMATIC_SUSPENSION_REASON = 'Compliance: automatic suspension for suspected off-app trips (L2).';

    public const DEACTIVATION_REVIEW_REASON = 'Compliance: suspended pending deactivation review (L3).';

    public const DEACTIVATED_REASON = 'Permanently deactivated after compliance review.';

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
                'deactivation_response_due_at' => $tier === SanctionTier::Deactivation ? OfflineTripFlag::newResponseDeadline() : null,
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
            ->notOverturned()
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

        if ($tier->requiresAdminReview()) {
            Log::critical('L3 sanction requires mandatory admin review before permanent deactivation', [
                'driver_id' => $driverUserId,
                'tier' => $tier->value,
            ]);
        }
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
            'suspension_reason' => self::AUTOMATIC_SUSPENSION_REASON,
        ]);

        $this->pushGateway->sendToUser($driver->user_id, [
            'title' => 'Account Suspended',
            'body' => "Your account has been suspended for {$hours} hours due to repeated offline trip violations.",
            'data' => ['type' => 'compliance_warning', 'suspension_hours' => $hours],
        ]);
    }

    // Industry standard: permanent deactivation requires mandatory admin review.
    private function deactivateDriver(Driver $driver): void
    {
        $driver->update([
            'is_online' => false,
            'status' => DriverStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => self::DEACTIVATION_REVIEW_REASON,
        ]);

        $hours = (int) config('offline_detection.deactivation_response_hours', 48);

        $this->pushGateway->sendToUser($driver->user_id, [
            'title' => 'Account Suspended — Under Review',
            'body' => "Your account has been suspended pending admin review due to repeated compliance violations. You have {$hours} hours to dispute this in the app before a decision is made.",
            'data' => ['type' => 'compliance_warning', 'pending_deactivation' => true, 'response_hours' => $hours],
        ]);
    }

    /**
     * Reinstate drivers whose automatic L2 suspension has run its course.
     *
     * @return int Number of drivers reinstated
     */
    public function liftExpiredSuspensions(): int
    {
        $hours = (int) config('offline_detection.suspension_hours', 48);

        $drivers = Driver::where('status', DriverStatus::Suspended)
            ->where('suspension_reason', self::AUTOMATIC_SUSPENSION_REASON)
            ->where('suspended_at', '<=', now()->subHours($hours))
            ->get();

        foreach ($drivers as $driver) {
            $driver->update([
                'status' => DriverStatus::Approved,
                'suspended_at' => null,
                'suspension_reason' => null,
            ]);

            AuditLog::record($driver, 'compliance_suspension_lifted', null, null, [
                'suspension_hours' => $hours,
            ]);

            try {
                $this->pushGateway->sendToUser($driver->user_id, [
                    'title' => 'Suspension Lifted',
                    'body' => 'Your compliance suspension has ended. You can go online again.',
                    'data' => ['type' => 'compliance_warning', 'reinstated' => true],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to notify driver of lifted suspension', [
                    'driver_id' => $driver->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $drivers->count();
    }

    /**
     * Bring the driver in line with a flag moved down a tier. Only the driver's
     * latest flag decides their current compliance sanction, and only
     * suspensions this service imposed are changed; one an admin made by hand
     * is left alone.
     */
    public function deEscalate(OfflineTripFlag $flag, SanctionTier $newTier): void
    {
        $driver = Driver::where('user_id', $flag->driver_id)->first();

        if (! $driver || ! $this->hasComplianceSuspension($driver)) {
            return;
        }

        $latestFlagId = OfflineTripFlag::forDriver($flag->driver_id)
            ->notOverturned()
            ->latest('flagged_at')
            ->value('id');

        if ($latestFlagId !== $flag->id) {
            return;
        }

        if ($newTier === SanctionTier::Suspension) {
            // The 48-hour clock runs from when the suspension began.
            $driver->update(['suspension_reason' => self::AUTOMATIC_SUSPENSION_REASON]);
            $hours = config('offline_detection.suspension_hours', 48);

            rescue(fn () => $this->pushGateway->sendToUser($driver->user_id, [
                'title' => 'Sanction Reduced',
                'body' => "Your account is no longer under deactivation review. Your suspension ends {$hours} hours after it began.",
                'data' => ['type' => 'compliance_warning', 'suspension_hours' => $hours],
            ]));

            return;
        }

        $driver->update([
            'status' => DriverStatus::Approved,
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        rescue(fn () => $this->pushGateway->sendToUser($driver->user_id, [
            'title' => 'Suspension Lifted',
            'body' => 'Your compliance sanction has been reduced to a warning. You can go online again.',
            'data' => ['type' => 'compliance_warning', 'reinstated' => true],
        ]));
    }

    /**
     * Undo what an overturned flag did to the driver. Called once the flag is
     * marked overturned. When the flag was the driver's latest, their
     * compliance status is set again from their remaining flags; overturning
     * a confirmed deactivation on appeal reactivates the driver the same way.
     * A suspension an admin made by hand for another reason is left alone.
     */
    public function reverseSanction(OfflineTripFlag $flag): void
    {
        $driver = Driver::where('user_id', $flag->driver_id)->first();
        if (! $driver) {
            return;
        }

        $reversingDeactivation = $flag->hasStandingDeactivation() && $driver->isDeactivated();

        if ($reversingDeactivation) {
            $flag->update(['deactivation_reversed_at' => now()]);
        } elseif (! $this->hasComplianceSuspension($driver)
            || OfflineTripFlag::forDriver($flag->driver_id)->notOverturned()->where('flagged_at', '>', $flag->flagged_at)->exists()) {
            return;
        }

        $this->applyStandingSanction($driver);
        $driver->refresh();

        if ($reversingDeactivation) {
            AuditLog::record($driver, 'compliance_deactivation_reversed', null, [
                'status' => DriverStatus::Deactivated->value,
            ], [
                'status' => $driver->status->value,
                'flag_id' => $flag->id,
            ]);
        }

        $message = match (true) {
            $driver->isApproved() && $reversingDeactivation => [
                'title' => 'Account Reinstated',
                'body' => 'Your appeal was successful. Your deactivation has been overturned and you can go online again.',
            ],
            $driver->isApproved() => [
                'title' => 'Account Reinstated',
                'body' => 'Your offline trip flag has been overturned and your account has been reinstated.',
            ],
            default => [
                'title' => 'Flag Overturned',
                'body' => 'One of your offline trip flags has been overturned. Your account stays suspended because of another flag.',
            ],
        };

        rescue(fn () => $this->pushGateway->sendToUser($driver->user_id, [
            ...$message,
            'data' => ['type' => 'compliance_warning', 'reinstated' => $driver->isApproved()],
        ]));
    }

    /**
     * Set the driver's compliance status from their latest flag that still
     * counts: deactivated after a confirmed Level 3, suspended pending review
     * after an unconfirmed one, suspended until 48 hours after a recent
     * Level 2, otherwise approved.
     */
    public function applyStandingSanction(Driver $driver): void
    {
        $hours = (int) config('offline_detection.suspension_hours', 48);

        $latest = OfflineTripFlag::forDriver($driver->user_id)
            ->notOverturned()
            ->latest('flagged_at')
            ->first();

        [$status, $reason, $since] = match (true) {
            (bool) $latest?->hasStandingDeactivation() => [DriverStatus::Deactivated, self::DEACTIVATED_REASON, $latest->flagged_at],
            $latest?->sanction_tier === SanctionTier::Deactivation => [DriverStatus::Suspended, self::DEACTIVATION_REVIEW_REASON, $latest->flagged_at],
            $latest?->sanction_tier === SanctionTier::Suspension && $latest->flagged_at->gt(now()->subHours($hours)) => [DriverStatus::Suspended, self::AUTOMATIC_SUSPENSION_REASON, $latest->flagged_at],
            default => [DriverStatus::Approved, null, null],
        };

        $driver->update([
            'status' => $status,
            'is_online' => $reason ? false : $driver->is_online,
            'suspended_at' => $since,
            'suspension_reason' => $reason,
        ]);
    }

    private function hasComplianceSuspension(Driver $driver): bool
    {
        return $driver->isSuspended()
            && in_array($driver->suspension_reason, [self::AUTOMATIC_SUSPENSION_REASON, self::DEACTIVATION_REVIEW_REASON], true);
    }
}
