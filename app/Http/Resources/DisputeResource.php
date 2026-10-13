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
                return collect($this->evidence_photos)->map(
                    fn (string $path) => $this->resolveEvidenceUrl($path),
                )->all();
            }),
            'evidence_audio' => $this->when($this->evidence_audio, function () {
                return $this->resolveEvidenceUrl($this->evidence_audio);
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

    private function resolveEvidenceUrl(string $path): ?string
    {
        $uploadsDisk = Storage::disk(config('filesystems.uploads'));

        try {
            return $uploadsDisk->temporaryUrl($path, now()->addMinutes(30));
        } catch (\RuntimeException) {
            try {
                return $uploadsDisk->url($path);
            } catch (\RuntimeException) {
                // Ignore — fall through to local fallback
            }
        }

        $localDisk = Storage::disk('local');

        try {
            return $localDisk->temporaryUrl($path, now()->addMinutes(30));
        } catch (\RuntimeException) {
            return $localDisk->url($path);
        }
    }
}
