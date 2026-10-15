<?php

namespace App\Console\Commands;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Events\DriverLocationUpdated;
use App\Models\Driver;
use App\Models\Ride;
use App\Services\DriverLocationService;
use App\Services\EtaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the demo drivers created by MonitoringDemoSeeder reporting their
 * location, the way the driver app does, so the admin monitoring and manual
 * assignment screens have live drivers on non-production environments.
 */
class SimulateDemoDriversCommand extends Command
{
    /**
     * Drivers stop short of their target so a ride never looks finished
     * without the driver app moving it on.
     */
    private const STOP_SHORT_KM = 0.2;

    private const STEP_FRACTION = 0.15;

    protected $signature = 'demo:simulate-drivers
        {--loop : Keep reporting until stopped}
        {--interval=15 : Seconds between reports when looping}';

    protected $description = 'Report locations for the demo drivers seeded by MonitoringDemoSeeder (non-production only)';

    public static function emailDomain(): string
    {
        return (string) config('matching.demo_driver_email_domain');
    }

    public static function homeCacheKey(string $driverId): string
    {
        return "demo_driver_home:{$driverId}";
    }

    public function handle(DriverLocationService $locationService, EtaService $etaService): int
    {
        if (app()->isProduction()) {
            $this->error('Demo drivers are not simulated in production.');

            return self::FAILURE;
        }

        $interval = max((int) $this->option('interval'), 2);

        do {
            $reported = $this->tick($locationService, $etaService);
            $this->info("Reported locations for {$reported} demo driver(s).");

            if ($this->option('loop')) {
                sleep($interval);
            }
        } while ($this->option('loop'));

        return self::SUCCESS;
    }

    private function tick(DriverLocationService $locationService, EtaService $etaService): int
    {
        $drivers = Driver::where('status', DriverStatus::Approved)
            ->where('is_online', true)
            ->whereHas('user', fn ($q) => $q->where('type', UserType::Driver)->where('email', 'like', '%@'.self::emailDomain()))
            ->get();

        if ($drivers->isEmpty()) {
            return 0;
        }

        $activeRides = Ride::whereIn('driver_id', $drivers->pluck('user_id'))
            ->whereIn('status', [
                RideStatus::Matched,
                RideStatus::DriverEnRoute,
                RideStatus::DriverArrived,
                RideStatus::InProgress,
            ])
            ->get()
            ->keyBy('driver_id');

        $reported = 0;

        foreach ($drivers as $driver) {
            $current = $locationService->getDriverLocation((string) $driver->id)
                ?? Cache::get(self::homeCacheKey((string) $driver->id));

            if (! $current) {
                continue;
            }

            $ride = $activeRides->get($driver->user_id);
            [$lat, $lng] = $this->nextPosition($driver, $ride, (float) $current['lat'], (float) $current['lng']);

            $heading = $this->bearing((float) $current['lat'], (float) $current['lng'], $lat, $lng);
            $speed = $ride && in_array($ride->status, [RideStatus::DriverEnRoute, RideStatus::InProgress, RideStatus::Matched], true)
                ? 28.0
                : 0.0;

            $locationService->updateLocation((string) $driver->id, $lat, $lng, $heading, $speed, $driver->user_id);

            $location = [
                'lat' => $lat,
                'lng' => $lng,
                'heading' => $heading,
                'speed' => $speed,
                'timestamp' => now()->timestamp,
            ];

            $eta = $ride ? $etaService->getMonitoringEta($ride, $location) : null;

            try {
                DriverLocationUpdated::dispatch(
                    (string) $driver->id,
                    $location,
                    $ride?->id,
                    $eta ? ['distance_km' => $eta['distance_km'], 'duration_minutes' => $eta['duration_minutes']] : null,
                );
            } catch (\Throwable) {
                // The location is stored; a WebSocket outage only delays the map.
            }

            $reported++;
        }

        return $reported;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function nextPosition(Driver $driver, ?Ride $ride, float $lat, float $lng): array
    {
        if ($ride?->status === RideStatus::DriverArrived) {
            return [(float) $ride->pickup_lat, (float) $ride->pickup_lng];
        }

        if ($ride) {
            [$targetLat, $targetLng] = $ride->status === RideStatus::InProgress
                ? [(float) $ride->destination_lat, (float) $ride->destination_lng]
                : [(float) $ride->pickup_lat, (float) $ride->pickup_lng];

            if ($this->distanceKm($lat, $lng, $targetLat, $targetLng) <= self::STOP_SHORT_KM) {
                return [$lat, $lng];
            }

            return [
                round($lat + ($targetLat - $lat) * self::STEP_FRACTION, 7),
                round($lng + ($targetLng - $lng) * self::STEP_FRACTION, 7),
            ];
        }

        $home = Cache::get(self::homeCacheKey((string) $driver->id)) ?? ['lat' => $lat, 'lng' => $lng];

        return [
            round((float) $home['lat'] + mt_rand(-80, 80) / 100000, 7),
            round((float) $home['lng'] + mt_rand(-80, 80) / 100000, 7),
        ];
    }

    private function distanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function bearing(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        if ($fromLat === $toLat && $fromLng === $toLng) {
            return 0.0;
        }

        $dLng = deg2rad($toLng - $fromLng);
        $y = sin($dLng) * cos(deg2rad($toLat));
        $x = cos(deg2rad($fromLat)) * sin(deg2rad($toLat))
            - sin(deg2rad($fromLat)) * cos(deg2rad($toLat)) * cos($dLng);

        return round(fmod(rad2deg(atan2($y, $x)) + 360, 360), 1);
    }
}
