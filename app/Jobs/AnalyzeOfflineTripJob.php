<?php

namespace App\Jobs;

use App\Models\Ride;
use App\Services\OfflineTripDetectionService;
use App\Services\SanctionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AnalyzeOfflineTripJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly string $rideId,
        private readonly string $driverUserId,
    ) {}

    public function handle(
        OfflineTripDetectionService $detectionService,
        SanctionService $sanctionService,
    ): void {
        $ride = Ride::find($this->rideId);
        if (! $ride) {
            Log::warning('AnalyzeOfflineTripJob: ride not found', ['ride_id' => $this->rideId]);

            return;
        }

        $cacheKey = "offline_monitoring:{$this->rideId}";
        $trajectory = Cache::get($cacheKey, []);

        if (empty($trajectory)) {
            Log::info('AnalyzeOfflineTripJob: no trajectory data', ['ride_id' => $this->rideId]);
            Cache::forget($cacheKey);

            return;
        }

        $intendedRoute = [
            'pickup_lat' => (float) $ride->pickup_lat,
            'pickup_lng' => (float) $ride->pickup_lng,
            'destination_lat' => (float) $ride->destination_lat,
            'destination_lng' => (float) $ride->destination_lng,
        ];

        $result = $detectionService->analyzeCollocation($trajectory, $intendedRoute);

        if ($result['flagged']) {
            Log::warning('Offline trip detected', [
                'ride_id' => $this->rideId,
                'driver_id' => $this->driverUserId,
                'collocation_points' => $result['collocation_points'],
                'route_match_percentage' => $result['route_match_percentage'],
            ]);

            $sanctionService->applyForRide($ride, $result);
        } else {
            Log::info('No offline trip detected', [
                'ride_id' => $this->rideId,
                'result' => $result['reason'] ?? 'below_threshold',
            ]);
        }

        Cache::forget($cacheKey);
    }

    public function uniqueId(): string
    {
        return "analyze_offline:{$this->rideId}";
    }
}
