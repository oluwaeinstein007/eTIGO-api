<?php

namespace App\Console\Commands;

use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverLocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DeleteDriverCommand extends Command
{
    protected $signature = 'driver:delete
                            {identifier : Driver phone number, email, user ID, or driver ID}
                            {--force : Force delete without confirmation prompt}';

    protected $description = 'Delete a driver account and all associated records from the database';

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

        if (! $this->option('force')) {
            if (! $this->confirm("Are you sure you want to permanently delete this account ({$user->phone})?", false)) {
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
            }

            // Remove profile photo
            if ($user->profile_photo_path) {
                Storage::disk(config('filesystems.uploads'))->delete($user->profile_photo_path);
            }

            // Delete associated rides if any (e.g. test rides) to allow full cascade
            Ride::where('driver_id', $user->id)->delete();

            // Clear tokens
            $user->tokens()->delete();
            $user->deviceTokens()->delete();
            $user->socialAccounts()->delete();

            if ($driver) {
                $driver->delete();
            }

            $user->delete();
        });

        $this->info("Driver account [{$identifier}] has been permanently deleted.");

        return self::SUCCESS;
    }
}
