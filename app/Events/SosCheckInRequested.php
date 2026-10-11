<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class SosCheckInRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $incidentId,
        public string $rideId,
        public string $userId,
        public int $timeoutSeconds,
    ) {}

    /** @return array<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'sos.check_in.requested';
    }

    public function broadcastWith(): array
    {
        return [
            'incident_id' => $this->incidentId,
            'ride_id' => $this->rideId,
            'timeout_seconds' => $this->timeoutSeconds,
            'message' => 'Are you safe? Please confirm within the timeout period.',
        ];
    }
}
