<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Dispute;
use App\Models\EvChargingStall;
use App\Models\EvReservation;
use App\Models\OfflineTripFlag;
use App\Models\PassengerBalanceDue;
use App\Models\PromoRedemption;
use App\Models\Rating;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\TripCarbonScore;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WalletTransaction;
use App\Services\DriverLocationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DeleteDriverCommand extends Command
{
    protected $signature = 'driver:delete
                            {identifier : Driver or user phone number, email, user ID, or driver ID}
                            {--force : Force delete without confirmation prompt}';

    protected $aliases = ['user:delete'];

    protected $description = 'Delete a driver or user account and all associated records from the database';

    public function handle(): int
    {
        $identifier = trim((string) $this->argument('identifier'));

        if ($identifier === '') {
            $this->error('An identifier (phone, email, or UUID) is required.');

            return self::FAILURE;
        }

        $cleanedPhone = preg_replace('/[^\d+]/', '', $identifier);
        $isUuid = Str::isUuid($identifier);

        $user = User::query()
            ->when($isUuid, function ($query) use ($identifier) {
                $query->where('id', $identifier)
                    ->orWhereHas('driver', fn ($q) => $q->where('id', $identifier));
            })
            ->when(! $isUuid, function ($query) use ($identifier, $cleanedPhone) {
                $query->where('phone', $identifier)
                    ->when(! empty($cleanedPhone), fn ($q) => $q->orWhere('phone', $cleanedPhone))
                    ->orWhere('email', $identifier);
            })
            ->first();

        if (! $user) {
            $this->error("No user found matching identifier: {$identifier}");

            return self::FAILURE;
        }

        $driver = $user->driver;

        $this->info("Found user: {$user->first_name} {$user->last_name} ({$user->id})");
        $this->info("Type: {$user->type->value}");
        $this->info("Phone: {$user->phone}");
        if ($user->email) {
            $this->info("Email: {$user->email}");
        }
        if ($driver) {
            $this->info("Driver ID: {$driver->id} (Status: {$driver->status->value})");
        }

        $identifierLabel = $user->phone ?: ($user->email ?: $user->id);

        if (! $this->option('force')) {
            if (! $this->confirm("Are you sure you want to permanently delete this account ({$identifierLabel})?", false)) {
                $this->warn('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        DB::transaction(function () use ($user, $driver) {
            if ($driver) {
                // Delete uploaded document files
                $docPaths = $driver->documents()->pluck('file_path')->filter()->all();
                if (! empty($docPaths)) {
                    Storage::disk(config('filesystems.uploads'))->delete($docPaths);
                }

                // Remove from live location index
                try {
                    app(DriverLocationService::class)->removeDriver($driver->id, $user->id);
                } catch (\Throwable) {
                    // Ignore if not present in redis/cache
                }

                // Detach vehicles
                Vehicle::where('driver_id', $driver->id)->update(['driver_id' => null]);

                // Delete driver-specific records
                $driver->documents()->delete();
                $driver->bankAccounts()->delete();
                $this->deleteAccountsFor($driver);
            }

            // Remove profile photo
            if ($user->profile_photo_path) {
                Storage::disk(config('filesystems.uploads'))->delete($user->profile_photo_path);
            }

            // Unlink non-cascading references on other rides
            Ride::where('cancelled_by', $user->id)->update(['cancelled_by' => null]);

            // Delete non-cascading offline trip flags
            OfflineTripFlag::where('driver_id', $user->id)->delete();
            OfflineTripFlag::where('passenger_id', $user->id)->update(['passenger_id' => null]);
            OfflineTripFlag::where('dispute_resolved_by_admin_id', $user->id)->update(['dispute_resolved_by_admin_id' => null]);

            // Delete associated rides where user was either driver OR passenger
            // Child records with cascadeOnDelete (payments, scheduled_rides, disputes, ratings on these rides) will cascade.
            Ride::where('driver_id', $user->id)
                ->orWhere('passenger_id', $user->id)
                ->delete();

            // Delete non-cascading ratings where user was rated or rater
            Rating::where('rated_by_user_id', $user->id)
                ->orWhere('rated_user_id', $user->id)
                ->delete();

            // Delete non-cascading disputes reported by this user
            Dispute::where('reported_by_user_id', $user->id)->delete();
            Dispute::where('resolved_by_admin_id', $user->id)->update(['resolved_by_admin_id' => null]);

            // Delete non-cascading SOS incidents
            SosIncident::where('triggered_by_user_id', $user->id)->delete();
            SosIncident::where('operator_id', $user->id)->update(['operator_id' => null]);

            // Delete non-cascading EV reservations
            EvReservation::where('driver_id', $user->id)->delete();
            EvChargingStall::where('current_vehicle_driver_id', $user->id)->update(['current_vehicle_driver_id' => null]);

            // Delete user-related records
            $user->savedPlaces()->delete();
            $user->paymentMethods()->delete();
            $user->notifications()->delete();
            $user->notificationPreferences()->delete();
            $user->gamificationProfile()?->delete();
            TripCarbonScore::where('user_id', $user->id)->delete();
            PromoRedemption::where('user_id', $user->id)->delete();
            PassengerBalanceDue::where('passenger_id', $user->id)->delete();
            PassengerBalanceDue::where('payee_driver_id', $user->id)->update(['payee_driver_id' => null]);
            $this->deleteAccountsFor($user);

            // Clear tokens
            $user->tokens()->delete();
            $user->deviceTokens()->delete();
            $user->socialAccounts()->delete();

            if ($driver) {
                $driver->delete();
            }

            $user->delete();
        });

        $accountType = $user->isDriver() ? 'Driver account' : 'Account';
        $this->info("{$accountType} [{$identifier}] has been permanently deleted.");

        return self::SUCCESS;
    }

    /**
     * Delete the ledger accounts owned by a model, clearing wallet transactions
     * first because that foreign key does not cascade.
     */
    private function deleteAccountsFor(Model $owner): void
    {
        $accountIds = Account::forOwner($owner)->pluck('id');
        if ($accountIds->isEmpty()) {
            return;
        }

        WalletTransaction::whereIn('account_id', $accountIds)->delete();
        Account::whereIn('id', $accountIds)->delete();
    }
}
