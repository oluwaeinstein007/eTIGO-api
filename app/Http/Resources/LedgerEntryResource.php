<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = $this->journal?->metadata ?? [];
        $rideId = $metadata['ride_id'] ?? null;
        $payoutId = $metadata['payout_id'] ?? null;

        return [
            'id' => $this->id,
            // debit or credit
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            // What the money was for (top-up, ride payment, refund, tip ...).
            'transaction_type' => $this->journal?->type ? [
                'value' => $this->journal->type->value,
                'label' => $this->journal->type->label(),
            ] : null,
            'amount' => $this->amount,
            'running_balance' => $this->running_balance,
            'created_at' => $this->created_at?->toIso8601String(),
            'source_type' => $rideId ? 'ride' : ($payoutId ? 'payout' : null),
            'source_id' => $rideId ?? $payoutId,
            // The account the entry posts to, and whose it is.
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'type' => $this->account->type->value,
                'type_label' => $this->account->type->label(),
                'owner_type' => AccountResource::ownerType($this->account),
                'owner' => $this->account->relationLoaded('owner') ? AccountResource::ownerSummary($this->account->owner) : null,
            ]),
            'journal' => new JournalResource($this->whenLoaded('journal')),
        ];
    }
}
