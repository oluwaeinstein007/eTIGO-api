<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'state' => $this->state,
            'region' => $this->region,
            'boundary' => $this->boundary,
            'area_sq_km' => $this->area_sq_km,
            'timezone' => $this->timezone,
            'currency_code' => $this->currency_code,
            'is_active' => $this->is_active,
            'vehicle_classes_count' => $this->whenCounted('vehicleClasses'),
            'drivers_count' => $this->whenCounted('drivers'),
            'trips_count' => $this->when(isset($this->trips_count), $this->trips_count),
            'active_rides_count' => $this->when(isset($this->active_rides_count), $this->active_rides_count),
            'active_drivers_count' => $this->when(isset($this->active_drivers_count), $this->active_drivers_count),
            'vehicle_classes' => VehicleClassResource::collection($this->whenLoaded('vehicleClasses')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
