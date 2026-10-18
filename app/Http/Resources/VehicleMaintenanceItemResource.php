<?php

namespace App\Http\Resources;

use App\Enums\MaintenanceItemStatus;
use App\Models\AppSetting;
use App\Models\VehicleMaintenanceItem;
use App\Services\VehicleMaintenanceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VehicleMaintenanceItem
 */
class VehicleMaintenanceItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $service = app(VehicleMaintenanceService::class);
        $settings = AppSetting::vehicleMaintenance();
        $today = $service->today();
        $dueAt = $service->localDate($this->due_at);
        $odometer = $this->relationLoaded('vehicle') ? $this->vehicle->estimatedOdometerKm() : null;
        $expired = $this->type->isLegalPaper() && $this->status === MaintenanceItemStatus::Expired;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'is_legal_paper' => $this->type->isLegalPaper(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'due_at' => $this->due_at?->toDateString(),
            'days_until_due' => $dueAt === null ? null : (int) $today->diffInDays($dueAt, false),
            'due_odometer_km' => $this->due_odometer_km,
            'km_until_due' => $this->due_odometer_km !== null && $odometer !== null ? $this->due_odometer_km - $odometer : null,
            'last_done_at' => $this->last_done_at?->toDateString(),
            'last_odometer_km' => $this->last_odometer_km,
            'blocks_driving' => $this->blocks_driving,
            // Expired paper still in its grace period: the day driving stops.
            'blocks_driving_from' => $expired && ! $this->blocks_driving && $dueAt !== null
                ? $service->blocksFrom($dueAt, $settings)->toDateString()
                : null,
            'message' => $service->reminderLine($this->resource, $settings),
        ];
    }
}
