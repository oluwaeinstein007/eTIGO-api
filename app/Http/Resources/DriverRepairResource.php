<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesUploadUrls;
use App\Models\DriverRepair;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriverRepair */
class DriverRepairResource extends JsonResource
{
    use ResolvesUploadUrls;

    public function toArray(Request $request): array
    {
        $vehicle = $this->vehicle ?? $this->driver?->driver?->vehicle;
        $isAdmin = (bool) $request->user()?->isAdmin();

        $attachments = array_map(function ($attachment) {
            if (is_array($attachment)) {
                if (! empty($attachment['path'])) {
                    $attachment['file_url'] = $this->resolveUploadUrl($attachment['path']) ?? ($attachment['file_url'] ?? null);
                }

                // Files from before this field existed were all the driver's.
                $attachment['uploaded_by'] ??= 'driver';
            }

            return $attachment;
        }, $this->attachments ?? []);

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
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            // What the workshop did, written by E-tiGo when the request is completed.
            'completion_summary' => $this->completion_summary,
            'can_cancel' => $this->resource->isOpen(),
            'progress_steps' => $this->progress_steps ?? [],
            'attachments' => $attachments,
            'vehicle_id' => $this->vehicle_id,
            'driver' => $this->when($isAdmin && $this->relationLoaded('driver'), fn () => $this->driver ? [
                'id' => $this->driver->id,
                'name' => trim("{$this->driver->first_name} {$this->driver->last_name}"),
                'phone' => $this->driver->phone,
            ] : null),
            'handled_by' => $this->when($isAdmin && $this->relationLoaded('handledBy'), fn () => $this->handledBy ? [
                'id' => $this->handledBy->id,
                'name' => trim("{$this->handledBy->first_name} {$this->handledBy->last_name}"),
            ] : null),
            'submitted_at' => $this->created_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
