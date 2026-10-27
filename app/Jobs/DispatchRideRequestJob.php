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
        $ride = Ride::with(['vehicleClass', 'city', 'passenger', 'scheduledRide'])
            ->find($this->rideId);

        if (! $ride || $ride->status !== RideStatus::Searching) {
            return;
        }

        $radius = $matchingService->calculateCurrentRadius($ride);
        $candidates = $matchingService->findEligibleDrivers($ride, $radius);

        if (empty($candidates)) {
            if ($matchingService->hasReachedMaxRadius($ride)) {
                $isWithinTimeoutWindow = $matchingService->searchDeadline($ride)->isFuture();

                if ($matchingService->canAutoRetry($ride) && $isWithinTimeoutWindow) {
                    $matchingService->resetForAutoRetry($ride);
                    $retryDelay = $matchingService->autoRetryDelaySeconds($ride);
                    Log::info('Auto-retrying matching for ride after expanding through all tiers', [
                        'ride_id' => $this->rideId,
                        'retry_count' => $matchingService->getAutoRetryCount($ride),
                        'delay_seconds' => $retryDelay,
                    ]);
                    self::dispatch($this->rideId)->delay(now()->addSeconds($retryDelay));

                    return;
                }

                MatchingTimeoutJob::dispatch($this->rideId);

                return;
            }

            $matchingService->expandRadius($ride);
            self::dispatch($this->rideId)->delay(now()->addSeconds(2));

            return;
        }

        $best = $candidates[0];
        $dispatchedAt = $matchingService->setDispatchedDriver($ride, $best['user_id']);
        $timeout = config('matching.driver_response_timeout', 20);
        $offer = RideRequestDispatched::fromRide($best['user_id'], $ride, $dispatchedAt);

        try {
            event($offer);
        } catch (\Throwable $e) {
            // The push notification below and the response timeout must still
            // go out when the WebSocket server is unavailable.
            Log::warning('Ride request broadcast failed', [
                'ride_id' => $ride->id,
                'driver_user_id' => $best['user_id'],
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // Same fields as the WebSocket event, so the app can show the
            // offer straight from a notification tap.
            $pushData = array_filter([
                'type' => 'ride_request',
                'response_timeout' => $timeout,
                ...$offer->broadcastWith(),
            ], static fn (mixed $value): bool => $value !== null);

            $pushGateway->sendToUser($best['user_id'], [
                'title' => 'New Ride Request',
                'body' => "Pickup at {$ride->pickup_address}",
                'data' => $pushData,
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
