<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromoCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'description' => $this->description,
            'discount_type' => $this->discount_type->value,
            'discount_type_label' => $this->discount_type->label(),
            'discount_value' => $this->discount_value,
            'max_discount_cap' => $this->max_discount_cap,
            'total_redemption_limit' => $this->when($isAdmin, $this->total_redemption_limit),
            'per_user_limit' => $this->per_user_limit,
            'starts_at' => $this->starts_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'geo_fence' => $this->when($isAdmin, $this->geo_fence),
            'min_order_count' => $this->when($isAdmin, $this->min_order_count),
            'max_order_count' => $this->when($isAdmin, $this->max_order_count),
            'min_tier_level' => $this->min_tier_level,
            'peak_only' => $this->peak_only,
            'off_peak_only' => $this->off_peak_only,
            'city_id' => $this->city_id,
            'vehicle_class_id' => $this->vehicle_class_id,
            'minimum_fare_amount' => $this->minimum_fare_amount,
            'is_active' => $this->is_active,
            'city' => new CityResource($this->whenLoaded('city')),
            'vehicle_class' => new VehicleClassResource($this->whenLoaded('vehicleClass')),
            'created_by' => $this->when($isAdmin, fn () => $this->whenLoaded('createdByAdmin', fn () => [
                'id' => $this->createdByAdmin->id,
                'name' => $this->createdByAdmin->first_name.' '.$this->createdByAdmin->last_name,
            ])),
            'total_redemptions' => $this->when($isAdmin && $this->relationLoaded('redemptions'), fn () => $this->redemptions->count()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
