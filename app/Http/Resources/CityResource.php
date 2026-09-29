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
            'boundary' => $this->boundary,
            'timezone' => $this->timezone,
            'currency_code' => $this->currency_code,
            'is_active' => $this->is_active,
            'vehicle_classes' => VehicleClassResource::collection($this->whenLoaded('vehicleClasses')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
