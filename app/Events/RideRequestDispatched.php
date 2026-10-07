<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class RideRequestDispatched implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $driverUserId,
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
    ) {}

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
        ];
    }
}
