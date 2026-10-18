<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesUploadUrls;
use App\Models\VehicleMaintenanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VehicleMaintenanceRecord
 */
class VehicleMaintenanceRecordResource extends JsonResource
{
    use ResolvesUploadUrls;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => $this->when($isAdmin && $this->relationLoaded('vehicle'), fn () => [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
                'is_fleet' => (bool) $this->vehicle->is_fleet,
            ]),
            'item_type' => $this->item_type->value,
            'item_label' => $this->item_type->label(),
            'performed_at' => $this->performed_at?->toDateString(),
            'odometer_km' => $this->odometer_km,
            'workshop' => $this->workshop,
            'notes' => $this->notes,
            'proof_url' => $this->proof_path ? $this->resolveUploadUrl($this->proof_path) : null,
            'driver_repair_id' => $this->driver_repair_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'rejection_reason' => $this->rejection_reason,
            'submitted_by' => $this->when($isAdmin && $this->relationLoaded('submitter'), fn () => $this->submitter === null ? null : [
                'id' => $this->submitter->id,
                'name' => trim($this->submitter->first_name.' '.$this->submitter->last_name),
                'type' => $this->submitter->type?->value,
            ]),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
