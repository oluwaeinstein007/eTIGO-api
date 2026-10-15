<?php

namespace App\Console\Commands;

use App\Enums\DriverStatus;
use App\Models\Vehicle;
use App\Notifications\VehicleFuelTypeRequiredNotification;
use Illuminate\Console\Command;

/**
 * Vehicles registered before fuel type was recorded have none. Drivers of
 * their own vehicles are asked to declare it (they may, once); fleet
 * vehicles belong to E-tiGo, so operations sets those.
 */
class RequestVehicleFuelTypesCommand extends Command
{
    protected $signature = 'vehicles:request-fuel-types {--dry-run : List the vehicles without notifying anyone}';

    protected $description = 'Ask drivers whose vehicle has no fuel type to set it, and list fleet vehicles operations must update';

    public function handle(): int
    {
        $missing = Vehicle::query()
            ->whereNull('fuel_type')
            ->with('driver.user')
            ->get();

        $own = $missing->filter(fn (Vehicle $vehicle) => ! $vehicle->is_fleet
            && $vehicle->driver?->user !== null
            && $vehicle->driver->status !== DriverStatus::Deactivated);
        $fleet = $missing->filter(fn (Vehicle $vehicle) => $vehicle->is_fleet);

        if (! $this->option('dry-run')) {
            $own->each(fn (Vehicle $vehicle) => $vehicle->driver->user->notify(new VehicleFuelTypeRequiredNotification));
        }

        $this->info(sprintf(
            '%s %d driver(s) to set their fuel type.',
            $this->option('dry-run') ? 'Would ask' : 'Asked',
            $own->count(),
        ));

        if ($fleet->isNotEmpty()) {
            $this->warn("{$fleet->count()} fleet vehicle(s) have no fuel type; set it with PUT /admin/fleet-vehicles/{vehicle}:");
            $this->table(['Vehicle', 'Plate', 'Make and model'], $fleet->map(fn (Vehicle $vehicle) => [
                $vehicle->id, $vehicle->plate_number, "{$vehicle->make} {$vehicle->model}",
            ])->all());
        }

        return self::SUCCESS;
    }
}
