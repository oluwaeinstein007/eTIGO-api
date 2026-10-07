<?php

namespace App\Jobs;

use App\Enums\RideStatus;
use App\Models\Ride;
use App\Services\DriverMatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DriverResponseTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $rideId,
        public readonly int $driverUserId,
    ) {}

    public function handle(DriverMatchingService $matchingService): void
    {
        $ride = Ride::find($this->rideId);

        if (! $ride || $ride->status !== RideStatus::Searching) {
            return;
        }

        $currentlyDispatched = $matchingService->getDispatchedDriverId($ride);
        if ($currentlyDispatched !== $this->driverUserId) {
            return;
        }

        Log::info('Driver response timed out', [
            'ride_id' => $this->rideId,
            'driver_user_id' => $this->driverUserId,
        ]);

        $matchingService->markDriverRejected($ride, $this->driverUserId);
        $matchingService->clearDispatchedDriver($ride);

        DispatchRideRequestJob::dispatch($this->rideId);
    }
}
