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
            // Short name for the adjustment ("AD-000123").
            'reference_number' => $this->reference_number,
            'account_id' => $this->account_id,
            // The account the money moves on and whose it is.
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'type' => $this->account->type->value,
                'type_label' => $this->account->type->label(),
                'owner_type' => AccountResource::ownerType($this->account),
                'owner' => $this->account->relationLoaded('owner') ? AccountResource::ownerSummary($this->account->owner) : null,
            ]),
            // credit adds money to the account, debit takes it.
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'amount' => $this->amount,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'created_by' => new UserResource($this->whenLoaded('createdBy')),
            'approved_by' => $this->when($this->approved_by_admin_id !== null, fn () => new UserResource($this->whenLoaded('approvedBy'))),
            'approved_at' => $this->when($this->approved_at !== null, $this->approved_at?->toIso8601String()),
            'rejected_by' => $this->when($this->rejected_by_admin_id !== null, fn () => new UserResource($this->whenLoaded('rejectedBy'))),
            'rejected_at' => $this->when($this->rejected_at !== null, fn () => $this->rejected_at->toIso8601String()),
            'rejection_reason' => $this->when($this->rejection_reason !== null, $this->rejection_reason),
            'journal_id' => $this->when($this->journal_id !== null, $this->journal_id),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
