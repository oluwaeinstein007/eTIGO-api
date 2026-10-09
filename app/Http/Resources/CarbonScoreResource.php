<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarbonScoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ride_id' => $this->ride_id,
            'user_id' => $this->user_id,
            'distance_km' => (float) $this->distance_km,
            'baseline_emission' => (float) $this->baseline_emission,
            'vehicle_emission' => (float) $this->vehicle_emission,
            'co2_saved' => (float) $this->co2_saved,
            'base_points' => $this->base_points,
            'multiplier_applied' => (float) $this->multiplier_applied,
            'multiplier_reason' => $this->multiplier_reason,
            'final_points' => $this->final_points,
            'created_at' => $this->created_at,
        ];
    }
}
