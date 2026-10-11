<?php

namespace App\Jobs;

use App\Models\Ride;
use App\Services\DriverLocationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MonitorCancellationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        private readonly string $rideId,
        private readonly string $driverUserId,
        private readonly int $sampleNumber = 0,
    ) {}

    public function handle(DriverLocationService $locationService): void
    {
        $ride = Ride::find($this->rideId);
        if (! $ride) {
            return;
        }

        $cacheKey = "offline_monitoring:{$this->rideId}";
        $monitoringWindow = config('offline_detection.monitoring_window_minutes', 15);
        $sampleInterval = config('offline_detection.location_sample_interval_seconds', 30);
        $maxSamples = ($monitoringWindow * 60) / $sampleInterval;

        if ($this->sampleNumber >= $maxSamples) {
            Log::info('Offline trip monitoring complete, dispatching analysis', [
                'ride_id' => $this->rideId,
                'total_samples' => $this->sampleNumber,
            ]);

            $analysisDelay = config('offline_detection.analysis_delay_minutes', 20);
            AnalyzeOfflineTripJob::dispatch($this->rideId, $this->driverUserId)
                ->delay(now()->addMinutes($analysisDelay));

            return;
        }

        $location = $locationService->getDriverLocation($this->driverUserId);

        if ($location) {
            $trajectory = Cache::get($cacheKey, []);
            $trajectory[] = [
                'lat' => $location['lat'],
                'lng' => $location['lng'],
                'timestamp' => $location['timestamp'],
                'speed' => $location['speed'] ?? 0,
                'heading' => $location['heading'] ?? 0,
            ];

            $ttl = ($monitoringWindow + 30) * 60;
            Cache::put($cacheKey, $trajectory, $ttl);
        }

        self::dispatch($this->rideId, $this->driverUserId, $this->sampleNumber + 1)
            ->delay(now()->addSeconds($sampleInterval));
    }
}
