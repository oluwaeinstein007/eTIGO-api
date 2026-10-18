<?php

namespace App\Http\Resources;

use App\Models\AppSetting;
use App\Models\DriverShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DriverShift
 */
class DriverShiftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = AppSetting::driverScheduling();
        $isAdmin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            // When the driver can go online, and when new offers stop.
            'online_from' => $this->onlineFrom($settings['shift_online_lead_minutes'])->toIso8601String(),
            'offers_stop_at' => $this->offersStopAt($settings['shift_offer_cutoff_minutes'])->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'missed_at' => $this->missed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
                'make' => $this->vehicle->make,
                'model' => $this->vehicle->model,
                'colour' => $this->vehicle->colour,
                // Who holds the car now (operations only).
                'held_by_driver_id' => $this->when($isAdmin, $this->vehicle->driver_id),
            ]),
            'driver' => $this->when($isAdmin && $this->relationLoaded('driver'), fn () => [
                'id' => $this->driver->id,
                'reference_number' => $this->driver->reference_number,
                'name' => trim(($this->driver->user?->first_name ?? '').' '.($this->driver->user?->last_name ?? '')),
                'phone' => $this->driver->user?->phone,
                'is_online' => $this->driver->is_online,
            ]),
            'created_by_admin_id' => $this->when($isAdmin, $this->created_by_admin_id),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
