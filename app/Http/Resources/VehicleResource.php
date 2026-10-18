<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Odometer, charge level and papers state are for the driver and
        // operations, not for a passenger seeing their driver's car.
        $internal = (bool) ($request->user()?->isAdmin() || $request->user()?->isDriver());

        return [
            'id' => $this->id,
            'driver_id' => $this->driver_id,
            'driver' => $this->when($this->relationLoaded('driver') && $this->driver, function () {
                return [
                    'id' => $this->driver->id,
                    'name' => $this->driver->user?->first_name.' '.$this->driver->user?->last_name,
                ];
            }),
            'make' => $this->make,
            'model' => $this->model,
            'colour' => $this->colour,
            'plate_number' => $this->plate_number,
            'year' => $this->year,
            'fuel_type' => $this->fuel_type?->value,
            'can_charge' => (bool) $this->fuel_type?->canCharge(),
            // A just-created vehicle has no is_fleet attribute until reloaded.
            'is_fleet' => (bool) $this->is_fleet,
            'is_assigned' => $this->when($this->is_fleet, $this->driver_id !== null),
            // Shared between drivers on a shift timetable (fleet cars).
            'shift_mode' => $this->when($internal, fn () => (bool) $this->shift_mode),
            'odometer_km' => $this->when($internal, fn () => $this->odometer_km),
            'estimated_odometer_km' => $this->when($internal, fn () => $this->resource->estimatedOdometerKm()),
            'battery_level_percent' => $this->when($internal && (bool) $this->fuel_type?->canCharge(), fn () => $this->battery_level_percent),
            // Expired papers keep the car off the road after the grace period.
            'is_blocked' => $this->when($internal, fn () => $this->blocked_at !== null),
            'block_reason' => $this->when($internal, fn () => $this->block_reason),
            'vehicle_class_id' => $this->vehicle_class_id,
            'vehicle_class' => new VehicleClassResource($this->whenLoaded('vehicleClass')),
            'created_at' => $this->created_at,
        ];
    }
}
