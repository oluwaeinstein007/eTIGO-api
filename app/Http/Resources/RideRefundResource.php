<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RideRefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount_kobo' => $this->amount_kobo,
            'driver_clawback_kobo' => $this->driver_clawback_kobo,
            'category' => $this->category?->value,
            'reason' => $this->reason,
            'dispute_id' => $this->dispute_id,
            'refunded_by' => $this->whenLoaded('refundedBy', fn () => $this->refundedBy ? [
                'id' => $this->refundedBy->id,
                'name' => trim("{$this->refundedBy->first_name} {$this->refundedBy->last_name}"),
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
