<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HoldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'ride_id' => $this->ride_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'captured_at' => $this->when($this->captured_at !== null, $this->captured_at?->toIso8601String()),
            'released_at' => $this->when($this->released_at !== null, $this->released_at?->toIso8601String()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
