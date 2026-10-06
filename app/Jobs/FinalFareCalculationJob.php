<?php

namespace App\Jobs;

use App\Models\Ride;
use App\Services\RideService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FinalFareCalculationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $rideId,
    ) {}

    public function handle(RideService $rideService): void
    {
        $ride = Ride::find($this->rideId);

        if (! $ride || $ride->final_fare_amount !== null) {
            return;
        }

        try {
            $result = $rideService->calculateFinalFare($ride);

            Log::info('Final fare calculated', [
                'ride_id' => $ride->id,
                'final_fare' => $result['final_fare'],
                'distance_km' => $result['distance_km'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Final fare calculation failed', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
