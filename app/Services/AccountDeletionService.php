<?php

namespace App\Services;

use App\Enums\BalanceDueStatus;
use App\Enums\DriverStatus;
use App\Enums\EvReservationStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\EvReservation;
use App\Models\PassengerBalanceDue;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Account deletion requested by a passenger or driver. A request logs the
 * user out and starts a grace period; logging in again cancels it. Once the
 * grace period is over the account is erased: deleted outright when it has
 * no rides, otherwise anonymised so trip and payment records stay intact.
 */
class AccountDeletionService
{
    public function __construct(private DriverLocationService $driverLocations) {}

    public static function graceDays(): int
    {
        return max(0, (int) config('accounts.deletion_grace_days', 30));
    }

    /**
     * Why the account cannot be deleted right now, or null when it can.
     */
    public function blockingReason(User $user): ?string
    {
        if ($user->type === UserType::Passenger) {
            if (Ride::where('passenger_id', $user->id)->whereIn('status', RideStatus::activeStatuses())->exists()) {
                return 'Cannot delete account while you have an active ride.';
            }

            $hasActiveScheduledRide = ScheduledRide::whereHas('ride', fn ($q) => $q->where('passenger_id', $user->id))
                ->whereNull('dispatched_at')
                ->whereNull('cancelled_at')
                ->exists();

            if ($hasActiveScheduledRide) {
                return 'Cannot delete account while you have an active scheduled ride.';
            }

            $hasOutstandingBalance = PassengerBalanceDue::where('passenger_id', $user->id)
                ->where('status', BalanceDueStatus::Outstanding)
                ->exists();

            if ($hasOutstandingBalance) {
                return 'Cannot delete account while you have an outstanding balance.';
            }
        } elseif ($user->type === UserType::Driver) {
            if (Ride::where('driver_id', $user->id)->whereIn('status', RideStatus::activeStatuses())->exists()) {
                return 'Cannot delete account while you have an active trip.';
            }

            $hasAssignedScheduledRide = ScheduledRide::where('reserved_driver_id', $user->id)
                ->whereNull('dispatched_at')
                ->whereNull('cancelled_at')
                ->exists();

            if ($hasAssignedScheduledRide) {
                return 'Cannot delete account while you have an assigned scheduled ride.';
            }

            $hasActiveEvCharging = EvReservation::where('driver_id', $user->id)
                ->where('status', EvReservationStatus::Active)
                ->exists();

            if ($hasActiveEvCharging) {
                return 'Cannot delete account while an EV charging session is active.';
            }
        }

        return null;
    }

    /**
     * Start the grace period: log the user out everywhere, stop pushes, take
     * a driver offline and free any EV stall held for them. Their data stays
     * until the purge. Returns the date the account will be erased.
     */
    public function requestDeletion(User $user): Carbon
    {
        DB::transaction(function () use ($user) {
            $user->forceFill(['deletion_requested_at' => now()])->save();
            $user->tokens()->delete();
            $user->deviceTokens()->delete();

            if ($user->type === UserType::Driver) {
                $this->cancelPendingEvReservations($user);

                if ($driver = $user->driver) {
                    $driver->update(['is_online' => false]);
                    $this->removeFromLocations($driver->id, $user->id);
                }
            }

            AuditLog::record($user, 'account_deletion_requested', $user);
        });

        return $this->scheduledFor($user);
    }

    /**
     * Cancel a pending deletion, as when the user logs in again within the
     * grace period. Returns true when there was one to cancel.
     */
    public function cancelDeletion(User $user): bool
    {
        if ($user->deletion_requested_at === null) {
            return false;
        }

        $user->forceFill(['deletion_requested_at' => null])->save();
        AuditLog::record($user, 'account_deletion_cancelled', $user);

        return true;
    }

    public function scheduledFor(User $user): ?Carbon
    {
        return $user->deletion_requested_at?->copy()->addDays(self::graceDays());
    }

    /**
     * Erase every account whose grace period has ended. An account that has
     * picked up something blocking since (an outstanding balance from a
     * dispute, say) is skipped and tried again on the next run.
     */
    public function purgeDue(): int
    {
        $purged = 0;

        User::whereNotNull('deletion_requested_at')
            ->where('deletion_requested_at', '<=', now()->subDays(self::graceDays()))
            ->where('type', '!=', UserType::Admin)
            ->each(function (User $user) use (&$purged) {
                if ($reason = $this->blockingReason($user)) {
                    Log::warning('Account deletion postponed.', ['user_id' => $user->id, 'reason' => $reason]);

                    return;
                }

                $this->purge($user);
                $purged++;
            });

        return $purged;
    }

    /**
     * Remove the account's personal data now.
     */
    public function purge(User $user): void
    {
        if ($user->type === UserType::Driver) {
            $this->cancelPendingEvReservations($user);
        }

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->deviceTokens()->delete();
            $user->savedPlaces()->delete();
            $user->paymentMethods()->delete();
            $user->socialAccounts()->delete();
            $user->notificationPreferences()->delete();
            $user->gamificationProfile()?->delete();

            if ($user->type === UserType::Driver && ($driver = $user->driver)) {
                $driver->update([
                    'is_online' => false,
                    'status' => DriverStatus::Suspended,
                ]);
                Vehicle::where('driver_id', $driver->id)->update(['driver_id' => null]);
                $this->removeFromLocations($driver->id, $user->id);
            }

            if ($user->profile_photo_path) {
                try {
                    Storage::disk(config('filesystems.uploads'))->delete($user->profile_photo_path);
                } catch (\Throwable) {
                }
            }

            $hasHistory = Ride::where('passenger_id', $user->id)
                ->orWhere('driver_id', $user->id)
                ->exists();

            if (! $hasHistory) {
                try {
                    // A savepoint, so a refused delete leaves the outer
                    // transaction usable (Postgres aborts it otherwise).
                    DB::transaction(fn () => $user->delete());

                    return;
                } catch (\Throwable) {
                    // Other records still point at the user: anonymise instead.
                }
            }

            $user->forceFill([
                'first_name' => 'Deleted',
                'last_name' => 'User',
                'phone' => null,
                'email' => null,
                'password' => null,
                'profile_photo_path' => null,
                'is_active' => false,
                'suspension_reason' => 'Account deleted by user.',
                'deletion_requested_at' => null,
            ])->save();

            AuditLog::record($user, 'account_deleted');
        });
    }

    private function cancelPendingEvReservations(User $user): void
    {
        EvReservation::where('driver_id', $user->id)
            ->whereIn('status', [
                EvReservationStatus::PendingReview,
                EvReservationStatus::Confirmed,
                EvReservationStatus::Reserved,
                EvReservationStatus::Queued,
            ])
            ->update(['status' => EvReservationStatus::Cancelled]);
    }

    private function removeFromLocations(string $driverId, string $userId): void
    {
        try {
            $this->driverLocations->removeDriver($driverId, $userId);
        } catch (\Throwable) {
        }
    }
}
