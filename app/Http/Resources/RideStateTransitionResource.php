<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RideStateTransitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_state' => $this->from_state?->value,
            'to_state' => $this->to_state?->value,
            'triggered_by_type' => $this->triggered_by_type,
            'triggered_by_id' => $this->triggered_by_id,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
