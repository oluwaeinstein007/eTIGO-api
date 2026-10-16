<?php

namespace App\Http\Resources;

use App\Models\PassengerBalanceDue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PassengerBalanceDue
 */
class BalanceDueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason?->value,
            'reason_label' => $this->reason?->label(),
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status?->value,
            'ride' => $this->whenLoaded('ride', fn () => $this->ride ? [
                'id' => $this->ride->id,
                'reference_number' => $this->ride->reference_number,
                'pickup_address' => $this->ride->pickup_address,
                'destination_address' => $this->ride->destination_address,
            ] : null),
            'passenger' => new UserResource($this->whenLoaded('passenger')),
            'paid_at' => $this->paid_at,
            'paid_via' => $this->paid_via,
            'waived_at' => $this->waived_at,
            'waive_reason' => $this->when($request->user()?->isAdmin(), $this->waive_reason),
            'created_at' => $this->created_at,
        ];
    }
}
