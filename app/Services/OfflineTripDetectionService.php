<?php

namespace App\Services;

use App\Jobs\MonitorCancellationJob;
use App\Models\Ride;
use App\Support\Polyline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OfflineTripDetectionService
{
    public function __construct(
        private readonly DriverLocationService $locationService,
    ) {}

    public const CONTEXT_CACHE_PREFIX = 'offline_monitoring_context:';

    public function shouldMonitor(Ride $ride): bool
    {
        $distance = $this->driverDistanceFromPickup($ride);

        return $distance !== null
            && $distance <= config('offline_detection.pickup_proximity_meters', 300);
    }

    /**
     * Start watching the driver after a cancellation close to the pickup, and
     * remember where the driver was when the ride was cancelled: it is part of
     * the evidence if the trip is flagged.
     */
    public function startMonitoringAfterCancellation(Ride $ride, string $driverUserId): bool
    {
        $location = $ride->driver_id ? $this->locationService->getDriverLocation($ride->driver_id) : null;
        $distance = $location ? $this->distanceFromPickup($ride, $location) : null;

        if ($distance === null || $distance > config('offline_detection.pickup_proximity_meters', 300)) {
            return false;
        }

        $ttlMinutes = config('offline_detection.monitoring_window_minutes', 15)
            + config('offline_detection.analysis_delay_minutes', 20)
            + 60;

        Cache::put(self::CONTEXT_CACHE_PREFIX.$ride->id, [
            'cancelled_at' => now()->toISOString(),
            'cancel_distance_from_pickup_meters' => round($distance, 1),
            'cancel_gps_accuracy_meters' => $location['accuracy'] ?? null,
            'cancel_lat' => (float) $location['lat'],
            'cancel_lng' => (float) $location['lng'],
        ], now()->addMinutes($ttlMinutes));

        MonitorCancellationJob::dispatch($ride->id, $driverUserId);

        return true;
    }

    /**
     * Metres between the driver's last reported position and the ride's pickup;
     * null when the driver's position is unknown.
     */
    public function driverDistanceFromPickup(Ride $ride): ?float
    {
        if (! $ride->driver_id) {
            return null;
        }

        $driverLocation = $this->locationService->getDriverLocation($ride->driver_id);

        return $driverLocation ? $this->distanceFromPickup($ride, $driverLocation) : null;
    }

    /**
     * @param  array{lat: float, lng: float}  $location
     */
    private function distanceFromPickup(Ride $ride, array $location): float
    {
        return $this->haversineDistance(
            (float) $ride->pickup_lat,
            (float) $ride->pickup_lng,
            $location['lat'],
            $location['lng'],
        );
    }

    /**
     * Compare the driver's positions after the cancellation with the
     * passenger's route: the planned driving route when there is one, else
     * the straight line from pickup to destination.
     *
     * @param  array<int, array{lat: float, lng: float, timestamp: int}>  $driverTrajectory
     * @param  array{pickup_lat: float, pickup_lng: float, destination_lat: float, destination_lng: float}  $intendedRoute
     * @param  string|null  $plannedPolyline  The planned route as an encoded polyline
     */
    public function analyzeCollocation(array $driverTrajectory, array $intendedRoute, ?string $plannedPolyline = null): array
    {
        $thresholdMeters = config('offline_detection.collocation_threshold_meters', 200);
        $minPoints = config('offline_detection.min_collocation_points', 3);
        $matchPercentage = config('offline_detection.route_match_percentage', 60);

        if (empty($driverTrajectory)) {
            return ['flagged' => false, 'reason' => 'no_trajectory_data'];
        }

        $routePoints = $plannedPolyline ? Polyline::decode($plannedPolyline) : [];
        // A road route has bends; two points is the maps fallback's straight line.
        $routeSource = count($routePoints) > 2 ? 'planned_route' : 'straight_line';

        if (count($routePoints) < 2) {
            $routePoints = [
                ['lat' => (float) $intendedRoute['pickup_lat'], 'lng' => (float) $intendedRoute['pickup_lng']],
                ['lat' => (float) $intendedRoute['destination_lat'], 'lng' => (float) $intendedRoute['destination_lng']],
            ];
        }

        $collocatedPoints = 0;
        $totalDistance = 0;
        $pointDistances = [];
        $onRouteTimestamps = [];
        $driverPath = [];

        foreach ($driverTrajectory as $point) {
            $minDistance = $this->distanceFromRoute((float) $point['lat'], (float) $point['lng'], $routePoints);

            $pointDistances[] = $minDistance;

            // Kept as evidence, so the compliance screen can draw where the
            // driver went after the cancellation.
            $driverPath[] = [
                'lat' => (float) $point['lat'],
                'lng' => (float) $point['lng'],
                'timestamp' => isset($point['timestamp']) ? (int) $point['timestamp'] : null,
                'accuracy' => isset($point['accuracy']) ? (float) $point['accuracy'] : null,
                'distance_from_route_meters' => round($minDistance, 1),
                'on_route' => $minDistance <= $thresholdMeters,
            ];

            if ($minDistance <= $thresholdMeters) {
                $collocatedPoints++;

                if (isset($point['timestamp'])) {
                    $onRouteTimestamps[] = (int) $point['timestamp'];
                }
            }

            $totalDistance += $minDistance;
        }

        $trajectoryCount = count($driverTrajectory);
        $percentage = $trajectoryCount > 0
            ? ($collocatedPoints / $trajectoryCount) * 100
            : 0;

        $averageDistance = $trajectoryCount > 0
            ? $totalDistance / $trajectoryCount
            : 0;

        $flagged = $collocatedPoints >= $minPoints && $percentage >= $matchPercentage;

        $timestamps = array_filter(array_map(fn ($point) => $point['timestamp'] ?? null, $driverTrajectory));
        $accuracies = array_values(array_filter(
            array_map(fn ($point) => $point['accuracy'] ?? null, $driverTrajectory),
            fn ($accuracy) => $accuracy !== null,
        ));

        Log::info('Offline trip collocation analysis', [
            'collocated_points' => $collocatedPoints,
            'total_points' => $trajectoryCount,
            'match_percentage' => round($percentage, 1),
            'average_distance_meters' => round($averageDistance, 1),
            'flagged' => $flagged,
        ]);

        return [
            'flagged' => $flagged,
            'collocation_points' => $collocatedPoints,
            'total_trajectory_points' => $trajectoryCount,
            'route_match_percentage' => round($percentage, 1),
            'average_distance_meters' => round($averageDistance, 1),
            'point_distances' => $pointDistances,
            'threshold_meters' => $thresholdMeters,
            'min_points_required' => $minPoints,
            'match_percentage_required' => $matchPercentage,
            // From the first to the last sample on the passenger's route.
            'on_route_duration_seconds' => count($onRouteTimestamps) > 1
                ? max($onRouteTimestamps) - min($onRouteTimestamps)
                : 0,
            'monitoring_duration_seconds' => count($timestamps) > 1 ? max($timestamps) - min($timestamps) : 0,
            // Null when the driver app does not report GPS accuracy.
            'average_gps_accuracy_meters' => $accuracies === []
                ? null
                : round(array_sum($accuracies) / count($accuracies), 1),
            // The line the samples were measured against.
            'route_source' => $routeSource,
            'route_polyline' => Polyline::encode(array_map(fn (array $p) => [$p['lat'], $p['lng']], $routePoints)),
            'driver_path' => $driverPath,
        ];
    }

    /**
     * Metres from a point to the nearest part of a route (a line through the
     * route's points). Each segment is measured on a flat projection around
     * the point, which is accurate to well under a metre at these distances.
     *
     * @param  list<array{lat: float, lng: float}>  $routePoints
     */
    private function distanceFromRoute(float $lat, float $lng, array $routePoints): float
    {
        $metresPerDegree = 6371000 * M_PI / 180;
        $lngScale = cos(deg2rad($lat));

        $project = fn (array $p) => [
            ($p['lng'] - $lng) * $lngScale * $metresPerDegree,
            ($p['lat'] - $lat) * $metresPerDegree,
        ];

        $nearest = PHP_FLOAT_MAX;
        $previous = $project($routePoints[0]);

        for ($i = 1, $count = count($routePoints); $i < $count; $i++) {
            $current = $project($routePoints[$i]);

            [$ax, $ay] = $previous;
            [$dx, $dy] = [$current[0] - $ax, $current[1] - $ay];
            $lengthSquared = $dx * $dx + $dy * $dy;

            // Where along the segment the point falls, clamped to its ends.
            $t = $lengthSquared > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $lengthSquared)) : 0;

            $nearest = min($nearest, hypot($ax + $t * $dx, $ay + $t * $dy));
            $previous = $current;
        }

        return $nearest;
    }

    public function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
