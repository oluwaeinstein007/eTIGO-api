<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ride_id' => $this->ride_id,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'resolution_notes' => $this->when(
                $this->resolution_notes !== null,
                fn () => $this->resolution_notes,
            ),
            'refund_amount' => $this->when(
                $this->refund_amount !== null,
                fn () => $this->refund_amount,
            ),
            'refund_currency' => $this->when(
                $this->refund_currency !== null,
                fn () => $this->refund_currency,
            ),
            'reported_by' => new UserResource($this->whenLoaded('reportedBy')),
            'resolved_by' => new UserResource($this->whenLoaded('resolvedBy')),
            'ride' => new RideResource($this->whenLoaded('ride')),
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
