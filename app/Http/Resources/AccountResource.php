<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'balance' => $this->balance,
            'available_balance' => $this->availableBalance(),
            'held' => $this->activeHoldsTotal(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
