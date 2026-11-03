<?php

namespace App\Http\Resources;

use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Models\EvChargingStall;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EvChargingStall */
class EvStallResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin();
        $withReservation = $isAdmin && $this->relationLoaded('openReservation');
        $reservation = $withReservation ? $this->openReservation : null;
        $heldFor = $this->status === EvStallStatus::Reserved && $reservation?->status === EvReservationStatus::Reserved
            ? $reservation->driver
            : null;

        return [
            'id' => $this->id,
            'stall_number' => $this->stall_number,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'occupied_since' => $this->occupied_since?->toISOString(),
            'estimated_departure_at' => $this->estimated_departure_at?->toISOString(),
            'current_driver' => $this->when(
                $isAdmin && $this->relationLoaded('currentDriver'),
                fn () => $this->currentDriver ? new EvDriverResource($this->currentDriver) : null,
            ),
            // The driver a reservation holds the stall for, on their way to
            // check in. Null on any other stall, and on a stall marked
            // reserved by hand.
            'reserved_driver' => $this->when(
                $withReservation,
                fn () => $heldFor ? new EvDriverResource($heldFor) : null,
            ),
            // The reservation using the stall: held for a driver (reserved)
            // or the driver charging (active). Its id is what the cancel and
            // end-session actions take.
            'reservation' => $this->when(
                $withReservation,
                fn () => $reservation ? [
                    'id' => $reservation->id,
                    'status' => [
                        'value' => $reservation->status->value,
                        'label' => $reservation->status->label(),
                    ],
                    'reserved_at' => $reservation->reserved_at?->toISOString(),
                    'check_in_deadline' => $reservation->checkInDeadline()?->toISOString(),
                ] : null,
            ),
            'station' => new EvStationResource($this->whenLoaded('station')),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
