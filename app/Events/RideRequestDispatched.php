<?php

namespace App\Events;

use App\Models\Ride;
use Carbon\CarbonInterface;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class RideRequestDispatched implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  bool  $isThirdParty  Booked by the passenger for someone else;
     *                              $passengerName is then the rider's first name
     */
    public function __construct(
        public string $driverUserId,
        public string $rideId,
        public string $pickupAddress,
        public string $destinationAddress,
        public float $pickupLat,
        public float $pickupLng,
        public float $destinationLat,
        public float $destinationLng,
        public float $fareEstimate,
        public string $currency,
        public string $vehicleClassName,
        public int $responseTimeoutSeconds,
        public string $paymentMethod,
        public string $passengerName,
        public ?float $passengerRating,
        public ?float $distanceKm,
        public ?float $durationMinutes,
        // Set for a scheduled ride: the pickup time the passenger booked.
        public ?string $scheduledPickupAt = null,
        public bool $isThirdParty = false,
        public ?string $dispatchedAt = null,
    ) {}

    /**
     * The offer as the driver sees it. The WebSocket event, the push and
     * GET /driver/ride-offer all build it here so they never drift apart.
     * Expects vehicleClass, passenger and scheduledRide to be loaded.
     */
    public static function fromRide(string $driverUserId, Ride $ride, CarbonInterface $dispatchedAt): self
    {
        return new self(
            driverUserId: $driverUserId,
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
            responseTimeoutSeconds: (int) config('matching.driver_response_timeout', 20),
            paymentMethod: $ride->payment_method?->value ?? '',
            passengerName: $ride->is_third_party
                ? (string) $ride->riderFirstName()
                : trim(($ride->passenger?->first_name ?? '').' '.($ride->passenger?->last_name ?? '')),
            passengerRating: $ride->passenger?->averageRating(),
            distanceKm: isset($ride->pricing_snapshot['distance_km'])
                ? (float) $ride->pricing_snapshot['distance_km']
                : null,
            durationMinutes: isset($ride->pricing_snapshot['duration_minutes'])
                ? (float) $ride->pricing_snapshot['duration_minutes']
                : null,
            scheduledPickupAt: $ride->scheduledRide?->scheduled_pickup_at?->toIso8601String(),
            isThirdParty: (bool) $ride->is_third_party,
            dispatchedAt: $dispatchedAt->toIso8601String(),
        );
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("driver.{$this->driverUserId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.request.dispatched';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ride_id' => $this->rideId,
            'pickup_address' => $this->pickupAddress,
            'destination_address' => $this->destinationAddress,
            'pickup_lat' => $this->pickupLat,
            'pickup_lng' => $this->pickupLng,
            'destination_lat' => $this->destinationLat,
            'destination_lng' => $this->destinationLng,
            'fare_estimate' => $this->fareEstimate,
            'currency' => $this->currency,
            'vehicle_class' => $this->vehicleClassName,
            'response_timeout_seconds' => $this->responseTimeoutSeconds,
            'payment_method' => $this->paymentMethod,
            'passenger_name' => $this->passengerName,
            'passenger_rating' => $this->passengerRating,
            'distance_km' => $this->distanceKm,
            'duration_minutes' => $this->durationMinutes,
            'scheduled_pickup_at' => $this->scheduledPickupAt,
            'is_third_party' => $this->isThirdParty,
            'dispatched_at' => $this->dispatchedAt,
        ];
    }
}
