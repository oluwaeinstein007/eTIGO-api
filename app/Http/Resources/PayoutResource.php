<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'failure_reason' => $this->when($this->failure_reason !== null, $this->failure_reason),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'approved_at' => $this->when($this->approved_at !== null, $this->approved_at?->toIso8601String()),
            'paid_at' => $this->when($this->paid_at !== null, $this->paid_at?->toIso8601String()),
            'bank_account' => new BankAccountResource($this->whenLoaded('bankAccount')),
            'driver' => $this->when($isAdmin, fn () => new DriverResource($this->whenLoaded('driver'))),
            'approved_by' => $this->when($isAdmin && $this->approved_by_admin_id, fn () => new UserResource($this->whenLoaded('approvedBy'))),
            'gateway_transfer_id' => $this->when($isAdmin, $this->gateway_transfer_id),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
