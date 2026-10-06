<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DriverLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array{lat: float, lng: float, heading: float, speed: float, timestamp: int}  $location
     * @param  array{distance_km: float, duration_minutes: float}|null  $eta
     */
    public function __construct(
        public int $driverId,
        public array $location,
        public ?string $rideId = null,
        public ?array $eta = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('admin.rides'),
        ];

        if ($this->rideId) {
            $channels[] = new PrivateChannel("ride.{$this->rideId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'driver.location.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'driver_id' => $this->driverId,
            'lat' => $this->location['lat'],
            'lng' => $this->location['lng'],
            'heading' => $this->location['heading'],
            'speed' => $this->location['speed'],
            'timestamp' => $this->location['timestamp'],
            'ride_id' => $this->rideId,
            'eta' => $this->eta,
        ];
    }
}
