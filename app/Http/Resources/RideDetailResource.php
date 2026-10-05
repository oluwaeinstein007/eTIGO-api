<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RideDetailResource extends JsonResource
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
            'pin_code' => $this->when(
                $this->shouldShowPin($request),
                $this->pin_code,
            ),
            'share_token' => $this->share_token,
            'fare_estimate_amount' => $this->fare_estimate_amount,
            'final_fare_amount' => $this->final_fare_amount,
            'fare_currency' => $this->fare_currency,
            'pricing_snapshot' => $this->pricing_snapshot,
            'payment_method' => $this->payment_method?->value,
            'payment_status' => $this->payment_status?->value,
            'cancellation_reason' => $this->cancellation_reason,
            'city' => new CityResource($this->whenLoaded('city')),
            'vehicle_class' => new VehicleClassResource($this->whenLoaded('vehicleClass')),
            'passenger' => new UserResource($this->whenLoaded('passenger')),
            'driver' => new UserResource($this->whenLoaded('driver')),
            'cancelled_by' => new UserResource($this->whenLoaded('cancelledByUser')),
            'state_transitions' => RideStateTransitionResource::collection($this->whenLoaded('stateTransitions')),
            'matched_at' => $this->matched_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function shouldShowPin(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $user->id === $this->passenger_id;
    }
}
