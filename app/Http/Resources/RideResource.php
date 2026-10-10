<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

class RideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'city_id' => $this->city_id,
            'vehicle_class_id' => $this->vehicle_class_id,
            'passenger_id' => $this->passenger_id,
            'driver_id' => $this->driver_id,
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
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'share_token' => $this->share_token,
            'fare_estimate_amount' => $this->fare_estimate_amount,
            'final_fare_amount' => $this->final_fare_amount,
            'fare_currency' => $this->fare_currency,
            'distance_km' => data_get($this->pricing_snapshot, 'distance_km'),
            'duration_minutes' => data_get($this->pricing_snapshot, 'duration_minutes'),
            'payment_method' => $this->payment_method?->value,
            'payment_status' => $this->payment_status?->value,
            'cancellation_reason' => $this->cancellation_reason,
            'city' => new CityResource($this->whenLoaded('city')),
            'vehicle_class' => new VehicleClassResource($this->whenLoaded('vehicleClass')),
            'passenger' => new UserResource($this->whenLoaded('passenger')),
            'driver' => new UserResource($this->whenLoaded('driver')),
            'cancelled_by' => new UserResource($this->whenLoaded('cancelledByUser')),
            'matched_at' => $this->matched_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'pin_code' => $this->when(
                $request->user()?->id === $this->passenger_id,
                fn () => Cache::get("ride:{$this->id}:pin_code"),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
