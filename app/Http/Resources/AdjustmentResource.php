<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'amount' => $this->amount,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'created_by' => new UserResource($this->whenLoaded('createdBy')),
            'approved_by' => $this->when($this->approved_by_admin_id !== null, fn () => new UserResource($this->whenLoaded('approvedBy'))),
            'approved_at' => $this->when($this->approved_at !== null, $this->approved_at?->toIso8601String()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
