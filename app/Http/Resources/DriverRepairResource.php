<?php

namespace App\Http\Resources;

use App\Models\DriverRepair;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriverRepair */
class DriverRepairResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vehicle = $this->vehicle ?? $this->driver?->driver?->vehicle;

        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'type' => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
            ],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'title' => $this->title,
            'details' => $this->details,
            'preferred_date' => $this->preferred_date?->format('Y-m-d'),
            'preferred_time' => $this->preferred_time,
            'requested_date' => $this->preferred_date?->format('Y-m-d'),
            'requested_time' => $this->preferred_time,
            'summary_note' => $this->summary_note,
            'vehicle_name' => $vehicle ? "{$vehicle->make} {$vehicle->model}" : null,
            'vehicle_plate' => $vehicle?->plate_number,
            'vehicle_color' => $vehicle?->colour,
            'workshop_visit' => $this->workshop_visit,
            'appointment' => $this->appointment,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_by' => $this->cancelled_by,
            'progress_steps' => $this->progress_steps ?? [],
            'attachments' => $this->attachments ?? [],
            'submitted_at' => $this->created_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
