<?php

namespace App\Http\Resources;

use App\Enums\TierLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TierConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tierLevel = TierLevel::tryFrom($this->tier_level);

        return [
            'id' => $this->id,
            'tier_level' => $this->tier_level,
            'tier_name' => $this->tier_name,
            'tier_label' => $tierLevel?->label(),
            'min_points_required' => $this->min_points_required,
            'booking_fee_discount_pct' => (float) $this->booking_fee_discount_pct,
            'ev_reservation_fee_waived' => $this->ev_reservation_fee_waived,
            'priority_matching_enabled' => $this->priority_matching_enabled,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
