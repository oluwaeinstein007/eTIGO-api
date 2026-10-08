<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class RideStatusUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $rideId,
        public string $passengerId,
        public ?string $driverId,
        public string $previousStatus,
        public string $status,
        public string $actorType,
        public ?string $actorId,
        public string $occurredAt,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("ride.{$this->rideId}")];
    }

    public function broadcastAs(): string
    {
        return 'ride.status.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ride_id' => $this->rideId,
            'passenger_id' => $this->passengerId,
            'driver_id' => $this->driverId,
            'previous_status' => $this->previousStatus,
            'status' => $this->status,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
