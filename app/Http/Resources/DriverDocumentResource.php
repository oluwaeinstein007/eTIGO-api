<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class DriverDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'file_url' => $this->file_path
                ? Storage::disk(config('filesystems.uploads'))->url($this->file_path)
                : null,
            'expires_at' => $this->expires_at,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
        ];
    }
}
