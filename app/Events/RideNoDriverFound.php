<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class RideNoDriverFound implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $rideId,
        public string $passengerId,
        public string $occurredAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("ride.{$this->rideId}")];
    }

    public function broadcastAs(): string
    {
        return 'ride.no-driver-found';
    }

    public function broadcastWith(): array
    {
        return [
            'ride_id' => $this->rideId,
            'passenger_id' => $this->passengerId,
            'status' => 'no_driver_found',
            'retry_allowed' => true,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
