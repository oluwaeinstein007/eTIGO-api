<?php

namespace App\Events;

use App\Models\EvChargingStation;
use App\Services\StallAvailabilityService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A station's stalls or queue changed. The figures are read when the event is
 * sent, so a burst of changes still ends on the latest state.
 */
class EvStationAvailabilityChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $stationId,
    ) {}

    /** @return array<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.ev-stations')];
    }

    public function broadcastAs(): string
    {
        return 'ev.station.availability_changed';
    }

    public function broadcastWith(): array
    {
        $station = EvChargingStation::find($this->stationId);

        if (! $station) {
            return ['station_id' => $this->stationId, 'deleted' => true];
        }

        return [
            'station_id' => $station->id,
            'deleted' => false,
            'status' => $station->status->value,
            'availability' => app(StallAvailabilityService::class)->getStationAvailability($station),
            'updated_at' => now()->toISOString(),
        ];
    }
}
