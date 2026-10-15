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
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'amount' => $this->amount,
            'running_balance' => $this->running_balance,
            'created_at' => $this->created_at?->toIso8601String(),
            'source_type' => $rideId ? 'ride' : ($payoutId ? 'payout' : null),
            'source_id' => $rideId ?? $payoutId,
            'journal' => new JournalResource($this->whenLoaded('journal')),
        ];
    }
}
