<?php

namespace App\Events;

use App\Enums\SosIncidentStatus;
use App\Models\SosIncident;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Any change to an open incident after it was raised: check-in sent, "I'm
 * safe", escalated, taken by an operator, dispatched, resolved or cancelled.
 */
class SosIncidentUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $incidentId,
        public ?string $referenceNumber,
        public string $rideId,
        public string $status,
        public string $previousStatus,
        public string $queueStatus,
        public ?string $operatorId,
        public ?string $escalatesAt,
        public string $updatedAt,
    ) {}

    public static function fromIncident(SosIncident $incident, SosIncidentStatus $previousStatus): self
    {
        return new self(
            incidentId: $incident->id,
            referenceNumber: $incident->reference_number,
            rideId: $incident->ride_id,
            status: $incident->status->value,
            previousStatus: $previousStatus->value,
            queueStatus: $incident->queueStatus()->value,
            operatorId: $incident->operator_id,
            escalatesAt: $incident->escalatesAt()?->toISOString(),
            updatedAt: now()->toISOString(),
        );
    }

    /** @return array<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.sos')];
    }

    public function broadcastAs(): string
    {
        return 'sos.incident.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'incident_id' => $this->incidentId,
            'reference_number' => $this->referenceNumber,
            'ride_id' => $this->rideId,
            'status' => $this->status,
            'previous_status' => $this->previousStatus,
            'queue_status' => $this->queueStatus,
            'operator_id' => $this->operatorId,
            'escalates_at' => $this->escalatesAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
