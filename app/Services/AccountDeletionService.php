<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\BalanceDueStatus;
use App\Enums\DriverStatus;
use App\Enums\EvReservationStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Models\Account;
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
    public const DEFAULT_GRACE_DAYS = 30;

    public function __construct(
        private DriverLocationService $driverLocations,
        private ReservationService $evReservations,
    ) {}

    /**
     * The grace period in whole days. A setting that is not a whole number
     * of at least 1 is ignored, so a bad value can never erase accounts early.
     */
    public static function graceDays(): int
    {
        $days = filter_var(config('accounts.deletion_grace_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($days === false) {
            Log::warning('ACCOUNT_DELETION_GRACE_DAYS is not a whole number of at least 1; using the default.', [
                'value' => config('accounts.deletion_grace_days'),
            ]);

            return self::DEFAULT_GRACE_DAYS;
        }

        return $days;
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

            // Money in the wallet would be lost at the purge, so it has to
            // be spent or refunded by support first.
            $wallet = Account::forOwner($user)->ofType(AccountType::PassengerWallet)->sum('balance');

            if ($wallet > 0) {
                return 'Cannot delete account while your wallet has '.self::naira($wallet)
                    .'. Use it on a ride or contact support for a refund first.';
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

            if ($driver = $user->driver) {
                // The purge frees the car; an active or paused agreement
                // would keep charging remittance on a car nobody drives.
                if ($driver->currentFleetAgreement()->exists()) {
                    return 'Cannot delete account while you have a fleet vehicle agreement. Ask your fleet manager to end it first.';
                }

                $earnings = Account::forOwner($driver)
                    ->whereIn('type', [AccountType::DriverEarningsPending, AccountType::DriverEarningsAvailable])
                    ->sum('balance');

                if ($earnings > 0) {
                    return 'Cannot delete account while you have '.self::naira($earnings)
                        .' in earnings. Withdraw them first (earnings still settling become available within a few hours).';
                }

                if ($earnings < 0) {
                    return 'Cannot delete account while you owe E-tiGo '.self::naira(-$earnings).'. Settle it first.';
                }
            }
        }

        return null;
    }

    private static function naira(int|string $kobo): string
    {
        return '₦'.number_format((int) $kobo / 100, 2);
    }

    /**
     * Start the grace period: log the user out everywhere, stop pushes, take
     * a driver offline and free any EV stall held for them. Their data stays
     * until the purge. Returns the date the account will be erased.
     */
    public function requestDeletion(User $user): Carbon
    {
        DB::transaction(function () use ($user) {
            $user->forceFill([
                'deletion_requested_at' => now(),
                'deletion_scheduled_for' => now()->addDays(self::graceDays()),
            ])->save();
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
     * Cancel a pending deletion, as when the user logs in again. This works
     * until the account is actually erased, which can be up to a day after
     * the scheduled date (the purge runs once a day). Returns true when
     * there was one to cancel.
     */
    public function cancelDeletion(User $user): bool
    {
        if ($user->deletion_requested_at === null) {
            return false;
        }

        $cancelled = DB::transaction(function () use ($user) {
            // The same row lock as purge(), so a login and a purge never
            // both act on the account.
            $locked = User::lockForUpdate()->find($user->id);

            if ($locked === null || $locked->deletion_requested_at === null) {
                return false;
            }

            $locked->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->save();
            AuditLog::record($locked, 'account_deletion_cancelled', $locked);

            return true;
        });

        if ($cancelled) {
            $user->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->syncOriginal();
        }

        return $cancelled;
    }

    public function scheduledFor(User $user): ?Carbon
    {
        return $user->deletion_scheduled_for;
    }

    /**
     * Erase every account whose grace period has ended. An account that has
     * picked up something blocking since (an outstanding balance from a
     * dispute, say) is skipped and tried again on the next run.
     */
    public function purgeDue(): int
    {
        $purged = 0;

        // By id, not offset: purged accounts leave the result, and offset
        // pages would then skip some.
        User::whereNotNull('deletion_scheduled_for')
            ->where('deletion_scheduled_for', '<=', now())
            ->where('type', '!=', UserType::Admin)
            ->chunkById(200, function ($users) use (&$purged) {
                foreach ($users as $user) {
                    try {
                        if ($this->purge($user)) {
                            $purged++;
                        }
                    } catch (\Throwable $e) {
                        // Nothing was committed for this account; the next run retries it.
                        Log::error('Account deletion failed; will retry.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $purged;
    }

    /**
     * Remove the account's personal data now. Returns false, changing
     * nothing, when the request was cancelled in the meantime or something
     * still blocks deletion (tried again on the next run).
     */
    public function purge(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $user = User::lockForUpdate()->find($user->id);

            if ($user === null || $user->deletion_requested_at === null) {
                return false;
            }

            if ($reason = $this->blockingReason($user)) {
                Log::warning('Account deletion postponed.', ['user_id' => $user->id, 'reason' => $reason]);

                return false;
            }

            if ($user->type === UserType::Driver) {
                $this->cancelPendingEvReservations($user);
            }

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

            // A failed delete throws and rolls the purge back, so the photo
            // is never left behind on an account marked as erased.
            if ($user->profile_photo_path) {
                Storage::disk(config('filesystems.uploads'))->delete($user->profile_photo_path);
            }

            $hasHistory = Ride::where('passenger_id', $user->id)
                ->orWhere('driver_id', $user->id)
                ->exists();

            if (! $hasHistory) {
                try {
                    // A savepoint, so a refused delete leaves the outer
                    // transaction usable (Postgres aborts it otherwise).
                    DB::transaction(fn () => $user->delete());

                    return true;
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
                'deletion_scheduled_for' => null,
            ])->save();

            AuditLog::record($user, 'account_deleted');

            return true;
        });
    }

    /**
     * Cancel each open EV request or reservation the way a driver's own
     * cancel does: the stall is released and offered to the queue, the queue
     * is renumbered and an in-window fee is refunded.
     */
    private function cancelPendingEvReservations(User $user): void
    {
        EvReservation::where('driver_id', $user->id)
            ->whereIn('status', [
                EvReservationStatus::PendingReview,
                EvReservationStatus::Confirmed,
                EvReservationStatus::Reserved,
                EvReservationStatus::Queued,
            ])
            ->get()
            ->each(fn (EvReservation $reservation) => $this->evReservations->cancel($reservation));
    }

    private function removeFromLocations(string $driverId, string $userId): void
    {
        try {
            $this->driverLocations->removeDriver($driverId, $userId);
        } catch (\Throwable) {
        }
    }
}
