<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleClassResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'capacity' => $this->capacity,
            'icon' => $this->icon,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'enabled_cities' => $this->when($this->relationLoaded('cities'), fn () => $this->cities->map(fn ($city) => [
                'id' => $city->id,
                'name' => $city->name,
            ])
            ),
            'drivers_count' => $this->when(isset($this->drivers_count), $this->drivers_count),
            'active_rides_count' => $this->when(isset($this->active_rides_count), $this->active_rides_count),
            'pivot' => $this->when($this->pivot !== null, fn () => [
                'is_active' => (bool) $this->pivot?->is_active,
                'sort_order' => (int) ($this->pivot?->sort_order ?? 0),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
