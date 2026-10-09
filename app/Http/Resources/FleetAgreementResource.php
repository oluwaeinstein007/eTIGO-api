<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FleetAgreementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'driver_id' => $this->driver_id,
            'vehicle_id' => $this->vehicle_id,
            'daily_remittance_target' => $this->daily_remittance_target,
            'total_remitted' => $this->total_remitted,
            'progress_percentage' => $this->progressPercentage(),
            'remaining_amount' => $this->when($isAdmin, fn () => $this->remainingAmount()),
            'total_vehicle_cost' => $this->when($isAdmin, $this->total_vehicle_cost),
            'agreement_start_date' => $this->agreement_start_date->toDateString(),
            'status' => $this->status->value,
            'shortfall_streak_days' => $this->shortfall_streak_days,
            'terminated_reason' => $this->when($this->terminated_reason !== null, $this->terminated_reason),
            'terminated_at' => $this->when($this->terminated_at !== null, $this->terminated_at),
            'completed_at' => $this->when($this->completed_at !== null, $this->completed_at),
            'paused_at' => $this->when($this->paused_at !== null, $this->paused_at),
            'daily_remittances' => DailyRemittanceResource::collection($this->whenLoaded('dailyRemittances')),
            'driver' => new DriverResource($this->whenLoaded('driver')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
