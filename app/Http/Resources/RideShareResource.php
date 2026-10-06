<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RideShareResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'pickup' => [
                'lat' => $this->pickup_lat,
                'lng' => $this->pickup_lng,
                'address' => $this->pickup_address,
            ],
            'destination' => [
                'lat' => $this->destination_lat,
                'lng' => $this->destination_lng,
                'address' => $this->destination_address,
            ],
            'vehicle_class' => new VehicleClassResource($this->whenLoaded('vehicleClass')),
            'driver' => $this->when($this->driver_id !== null, function () {
                return [
                    'first_name' => $this->driver?->first_name,
                ];
            }),
            'started_at' => $this->started_at,
        ];
    }
}
