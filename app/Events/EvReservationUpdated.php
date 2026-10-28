<?php

namespace App\Events;

use App\Http\Resources\EvReservationResource;
use App\Models\EvReservation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;

/**
 * A driver's charging request or reservation changed (confirmed, stall held,
 * moved up the queue, ended...). Sent to that driver's app, which can update
 * the screen without waiting for a push or a refetch. The reservation is read
 * when the event is sent, so a burst of changes ends on the latest state.
 */
class EvReservationUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $reservationId,
        public string $driverUserId,
    ) {}

    /** @return array<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("driver.{$this->driverUserId}")];
    }

    public function broadcastAs(): string
    {
        return 'ev.reservation.updated';
    }

    public function broadcastWith(): array
    {
        $reservation = EvReservation::with(['station', 'stall'])->find($this->reservationId);

        if (! $reservation) {
            return ['reservation_id' => $this->reservationId, 'deleted' => true];
        }

        return [
            'reservation_id' => $reservation->id,
            'deleted' => false,
            // The same object as GET /driver/ev-reservations/{id}, as the driver sees it.
            'reservation' => (new EvReservationResource($reservation))->resolve(Request::create('/')),
        ];
    }
}
