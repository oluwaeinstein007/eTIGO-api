<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyRemittanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'target_amount' => $this->target_amount,
            'remitted_amount' => $this->remitted_amount,
            'shortfall_amount' => $this->shortfall_amount,
            'target_met' => $this->hasMetTarget(),
            'target_met_at' => $this->target_met_at,
            'ride_count' => $this->ride_count,
            'total_fares' => $this->total_fares,
            'driver_earnings' => $this->driver_earnings,
            'settled' => $this->settled,
            'excused_reason' => $this->when($this->excused_reason !== null, $this->excused_reason),
            'created_at' => $this->created_at,
        ];
    }
}
