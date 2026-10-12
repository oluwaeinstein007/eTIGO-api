<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class DisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'ride_id' => $this->ride_id,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'description' => $this->description,
            'evidence_photos' => $this->when($this->evidence_photos, function () {
                $disk = config('filesystems.uploads');

                return collect($this->evidence_photos)->map(
                    fn (string $path) => Storage::disk($disk)->url($path),
                )->all();
            }),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'resolution_notes' => $this->resolution_notes,
            'refund_amount' => $this->refund_amount,
            'refund_currency' => $this->refund_currency,
            'reported_by' => new UserResource($this->whenLoaded('reportedBy')),
            'resolved_by' => new UserResource($this->whenLoaded('resolvedBy')),
            'ride' => new RideResource($this->whenLoaded('ride')),
            'notes' => DisputeNoteResource::collection($this->whenLoaded('notes')),
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
