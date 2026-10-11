<?php

namespace App\Services;

use App\Models\Ride;
use Illuminate\Support\Facades\Log;

class OfflineTripDetectionService
{
    public function __construct(
        private readonly DriverLocationService $locationService,
    ) {}

    public function shouldMonitor(Ride $ride): bool
    {
        if (! $ride->driver_id) {
            return false;
        }

        $driverLocation = $this->locationService->getDriverLocation($ride->driver_id);
        if (! $driverLocation) {
            return false;
        }

        $proximityMeters = config('offline_detection.pickup_proximity_meters', 300);
        $distance = $this->haversineDistance(
            (float) $ride->pickup_lat,
            (float) $ride->pickup_lng,
            $driverLocation['lat'],
            $driverLocation['lng'],
        );

        return $distance <= $proximityMeters;
    }

    /**
     * @param  array<int, array{lat: float, lng: float, timestamp: int}>  $driverTrajectory
     * @param  array{pickup_lat: float, pickup_lng: float, destination_lat: float, destination_lng: float}  $intendedRoute
     */
    public function analyzeCollocation(array $driverTrajectory, array $intendedRoute): array
    {
        $thresholdMeters = config('offline_detection.collocation_threshold_meters', 200);
        $minPoints = config('offline_detection.min_collocation_points', 3);
        $matchPercentage = config('offline_detection.route_match_percentage', 60);

        if (empty($driverTrajectory)) {
            return ['flagged' => false, 'reason' => 'no_trajectory_data'];
        }

        $routePoints = $this->interpolateRoute(
            $intendedRoute['pickup_lat'],
            $intendedRoute['pickup_lng'],
            $intendedRoute['destination_lat'],
            $intendedRoute['destination_lng'],
        );

        $collocatedPoints = 0;
        $totalDistance = 0;
        $pointDistances = [];

        foreach ($driverTrajectory as $point) {
            $minDistance = PHP_FLOAT_MAX;

            foreach ($routePoints as $routePoint) {
                $distance = $this->haversineDistance(
                    $point['lat'],
                    $point['lng'],
                    $routePoint['lat'],
                    $routePoint['lng'],
                );
                $minDistance = min($minDistance, $distance);
            }

            $pointDistances[] = $minDistance;

            if ($minDistance <= $thresholdMeters) {
                $collocatedPoints++;
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
        ];
    }

    /**
     * @return array<int, array{lat: float, lng: float}>
     */
    private function interpolateRoute(
        float $startLat,
        float $startLng,
        float $endLat,
        float $endLng,
        int $segments = 20,
    ): array {
        $points = [];

        for ($i = 0; $i <= $segments; $i++) {
            $fraction = $i / $segments;
            $points[] = [
                'lat' => $startLat + ($endLat - $startLat) * $fraction,
                'lng' => $startLng + ($endLng - $startLng) * $fraction,
            ];
        }

        return $points;
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
