<?php

namespace App\Jobs;

use App\Contracts\PushNotificationGateway;
use App\Enums\RideStatus;
use App\Events\RideRequestDispatched;
use App\Models\Ride;
use App\Services\DriverMatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchRideRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $rideId,
    ) {}

    public function handle(
        DriverMatchingService $matchingService,
        PushNotificationGateway $pushGateway,
    ): void {
        $ride = Ride::with(['vehicleClass', 'city'])->find($this->rideId);

        if (! $ride || $ride->status !== RideStatus::Searching) {
            return;
        }

        $radius = $matchingService->calculateCurrentRadius($ride);
        $candidates = $matchingService->findEligibleDrivers($ride, $radius);

        if (empty($candidates)) {
            if ($matchingService->hasReachedMaxRadius($ride)) {
                MatchingTimeoutJob::dispatch($this->rideId);

                return;
            }

            $matchingService->markDriverRejected($ride, 0);
            self::dispatch($this->rideId)->delay(now()->addSeconds(2));

            return;
        }

        $best = $candidates[0];
        $matchingService->setDispatchedDriver($ride, $best['user_id']);

        $timeout = config('matching.driver_response_timeout', 30);

        RideRequestDispatched::dispatch(
            driverUserId: $best['user_id'],
            rideId: $ride->id,
            pickupAddress: $ride->pickup_address,
            destinationAddress: $ride->destination_address,
            pickupLat: (float) $ride->pickup_lat,
            pickupLng: (float) $ride->pickup_lng,
            destinationLat: (float) $ride->destination_lat,
            destinationLng: (float) $ride->destination_lng,
            fareEstimate: (float) $ride->fare_estimate_amount,
            currency: $ride->fare_currency ?? 'NGN',
            vehicleClassName: $ride->vehicleClass?->display_name ?? $ride->vehicleClass?->name ?? '',
            responseTimeoutSeconds: $timeout,
        );

        try {
            $pushGateway->sendToUser($best['user_id'], [
                'title' => 'New Ride Request',
                'body' => "Pickup at {$ride->pickup_address}",
                'data' => [
                    'type' => 'ride_request',
                    'ride_id' => (string) $ride->id,
                    'response_timeout' => $timeout,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Push notification failed for ride dispatch', [
                'ride_id' => $ride->id,
                'driver_user_id' => $best['user_id'],
                'error' => $e->getMessage(),
            ]);
        }

        DriverResponseTimeoutJob::dispatch($this->rideId, $best['user_id'])
            ->delay(now()->addSeconds($timeout));

        Log::info('Ride request dispatched to driver', [
            'ride_id' => $ride->id,
            'driver_user_id' => $best['user_id'],
            'distance_km' => $best['distance_km'],
            'radius_km' => $radius,
        ]);
    }
}
