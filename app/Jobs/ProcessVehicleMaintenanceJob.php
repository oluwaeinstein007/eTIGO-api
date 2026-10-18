<?php

namespace App\Jobs;

use App\Models\Vehicle;
use App\Services\OperationsAlertService;
use App\Services\VehicleMaintenanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily (06:00 Lagos) vehicle maintenance run (OQ-19): recompute every
 * car's items, start or lift the expired-papers block, remind drivers of
 * what has become due, overdue or expired (once per change), and send
 * operations one digest of fleet cars needing work. Creates the items of
 * cars that predate the feature. One failing car is logged and skipped.
 */
class ProcessVehicleMaintenanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(VehicleMaintenanceService $maintenance, OperationsAlertService $operations): void
    {
        $digest = [];

        Vehicle::query()
            ->with('driver')
            ->lazyById()
            ->each(function (Vehicle $vehicle) use ($maintenance, &$digest) {
                try {
                    $maintenance->ensureItems($vehicle);
                    $items = $maintenance->refresh($vehicle);
                    $digest = [...$digest, ...$maintenance->sendReminders($vehicle->refresh(), $items)];
                } catch (\Throwable $e) {
                    Log::error('Vehicle maintenance run failed for a vehicle', ['vehicle_id' => $vehicle->id, 'error' => $e->getMessage()]);
                }
            });

        if ($digest !== []) {
            $operations->send(
                label: 'Fleet maintenance',
                headline: count($digest) === 1 ? '1 fleet item needs attention' : count($digest).' fleet items need attention',
                body: 'These fleet cars have overdue maintenance or expired papers. Overdue maintenance only warns; expired papers stop the car after the grace period.',
                lines: $digest,
                actionPath: '/admin/vehicle-maintenance?status=overdue,expired',
                actionLabel: 'Open fleet maintenance',
            );
        }
    }
}
