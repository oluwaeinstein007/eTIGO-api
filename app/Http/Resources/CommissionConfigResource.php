<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rate' => (float) $this->rate,
            'driver_id' => $this->driver_id,
            // The driver on their own rate; null on the global rate.
            'driver' => $this->when($this->driver_id !== null && $this->relationLoaded('driver'), fn () => $this->driver ? [
                'id' => $this->driver->id,
                'reference_number' => $this->driver->reference_number,
                'user_id' => $this->driver->user_id,
                'name' => trim(($this->driver->user?->first_name ?? '').' '.($this->driver->user?->last_name ?? '')),
                'phone' => $this->driver->user?->phone,
            ] : null),
            'is_global' => $this->isGlobal(),
            'is_active' => $this->is_active,
            'created_by' => new UserResource($this->whenLoaded('createdByAdmin')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
