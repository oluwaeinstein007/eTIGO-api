<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'card_brand' => $this->card_brand,
            'card_last_four' => $this->card_last_four,
            'card_expiry_month' => $this->card_expiry_month,
            'card_expiry_year' => $this->card_expiry_year,
            'is_default' => $this->is_default,
            'created_at' => $this->created_at,
        ];
    }
}
