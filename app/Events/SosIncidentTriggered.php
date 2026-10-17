<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class SosIncidentTriggered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $incidentId,
        public string $rideId,
        public string $triggeredByUserId,
        public string $triggerType,
        public float $gpsLat,
        public float $gpsLng,
        public string $status,
        public string $triggeredAt,
        public ?string $referenceNumber = null,
    ) {}

    /** @return array<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.sos')];
    }

    public function broadcastAs(): string
    {
        return 'sos.incident.triggered';
    }

    public function broadcastWith(): array
    {
        return [
            'incident_id' => $this->incidentId,
            'reference_number' => $this->referenceNumber,
            'ride_id' => $this->rideId,
            'triggered_by_user_id' => $this->triggeredByUserId,
            'trigger_type' => $this->triggerType,
            'gps_lat' => $this->gpsLat,
            'gps_lng' => $this->gpsLng,
            'status' => $this->status,
            'triggered_at' => $this->triggeredAt,
        ];
    }
}
