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
            'is_global' => $this->isGlobal(),
            'is_active' => $this->is_active,
            'created_by' => new UserResource($this->whenLoaded('createdByAdmin')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
