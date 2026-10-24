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
            'launch_status' => $this->launch_status?->value,
            'launch_status_label' => $this->launch_status?->label(),
            $this->mergeWhen((bool) $request->user()?->isAdmin(), fn () => [
                'launch_date' => $this->launch_date?->toDateString(),
                'launched_at' => $this->launched_at,
                'paused_at' => $this->paused_at,
                'pause_reason' => $this->pause_reason,
                'pause_scheduled_rides' => $this->pause_scheduled_rides,
                'readiness_confirmations' => $this->readiness_confirmations,
            ]),
            'waitlist_count' => $this->whenCounted('waitlistEntries'),
            'vehicle_classes_count' => $this->whenCounted('vehicleClasses'),
            'drivers_count' => $this->whenCounted('drivers'),
            'unpriced_vehicle_classes_count' => $this->when(isset($this->unpriced_vehicle_classes_count), $this->unpriced_vehicle_classes_count),
            // Admin city list: cities whose boundary overlaps this one (saved
            // before overlaps were refused). Empty when there are none.
            'overlapping_cities' => $this->when(isset($this->overlapping_cities), $this->overlapping_cities),
            'trips_count' => $this->when(isset($this->trips_count), $this->trips_count),
            'active_rides_count' => $this->when(isset($this->active_rides_count), $this->active_rides_count),
            'active_drivers_count' => $this->when(isset($this->active_drivers_count), $this->active_drivers_count),
            'vehicle_classes' => VehicleClassResource::collection($this->whenLoaded('vehicleClasses')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
