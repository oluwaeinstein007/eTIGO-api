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
            // Short name for the agreement ("FA-000123") for staff and the driver.
            'reference_number' => $this->reference_number,
            'driver_id' => $this->driver_id,
            'vehicle_id' => $this->vehicle_id,
            'daily_remittance_target' => $this->daily_remittance_target,
            'total_vehicle_cost' => $this->total_vehicle_cost,
            'total_remitted' => $this->total_remitted,
            'remaining_amount' => $this->remainingAmount(),
            'progress_percentage' => $this->progressPercentage(),
            'agreement_start_date' => $this->agreement_start_date->toDateString(),
            'status' => $this->status->value,
            'shortfall_streak_days' => $this->shortfall_streak_days,
            'shortfall_flag' => $this->when($isAdmin, $this->shortfall_flag),
            'shortfall_flagged_at' => $this->when($isAdmin && $this->shortfall_flagged_at, $this->shortfall_flagged_at),
            'vehicle_return_status' => $this->when($isAdmin && $this->vehicle_return_status, $this->vehicle_return_status),
            'outstanding_amount' => $this->when($isAdmin, $this->outstanding_amount),
            'settlement_amount' => $this->when($isAdmin, $this->settlement_amount),
            'settlement_notes' => $this->when($isAdmin && $this->settlement_notes, $this->settlement_notes),
            'settled_at' => $this->when($this->settled_at !== null, $this->settled_at),
            'terminated_reason' => $this->when($this->terminated_reason !== null, $this->terminated_reason),
            'terminated_at' => $this->when($this->terminated_at !== null, $this->terminated_at),
            'completed_at' => $this->when($this->completed_at !== null, $this->completed_at),
            'paused_at' => $this->when($this->paused_at !== null, $this->paused_at),
            'paused_reason' => $this->when($isAdmin && $this->paused_at !== null, fn () => $this->paused_reason),
            'daily_remittances' => DailyRemittanceResource::collection($this->whenLoaded('dailyRemittances')),
            'driver' => new DriverResource($this->whenLoaded('driver')),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
