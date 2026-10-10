<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromoRedemptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'promo_code' => new PromoCodeResource($this->whenLoaded('promoCode')),
            'user' => new UserResource($this->whenLoaded('user')),
            'ride_id' => $this->ride_id,
            'discount_amount' => $this->discount_amount,
            'redeemed_at' => $this->redeemed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
