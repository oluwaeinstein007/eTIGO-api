<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * The public view of a ride behind a share link: what someone following the
 * trip needs to recognise the car and see where it is, and nothing about the
 * passenger's account. Also the data behind the no-app tracking page sent to
 * the rider of a third-party booking.
 */
class RideShareResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $vehicle = $this->driver?->driver?->vehicle;

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
            'driver' => $this->when($this->driver_id !== null, function () use ($vehicle) {
                return [
                    'first_name' => $this->driver?->first_name,
                    'profile_photo_url' => $this->driver?->profile_photo_path
                        ? Storage::disk(config('filesystems.uploads'))->url($this->driver->profile_photo_path)
                        : null,
                    'vehicle' => $vehicle ? [
                        'make' => $vehicle->make,
                        'model' => $vehicle->model,
                        'colour' => $vehicle->colour,
                        'plate_number' => $vehicle->plate_number,
                    ] : null,
                ];
            }),
            'is_third_party' => (bool) $this->is_third_party,
            'rider_first_name' => $this->when($this->is_third_party, fn () => $this->resource->riderFirstName()),
            'booked_by_first_name' => $this->when($this->is_third_party, fn () => $this->passenger?->first_name),
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
