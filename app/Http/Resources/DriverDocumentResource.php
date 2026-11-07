<?php

namespace App\Http\Resources;

use App\Support\UploadLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            // Operations see the whole number; the driver sees the last 4.
            'document_number' => $this->when($this->document_number !== null, fn () => $request->user()?->isAdmin()
                ? $this->document_number
                : '•••• '.mb_substr($this->document_number, -4)),
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'file_url' => $this->when($this->file_path, fn () => UploadLinks::url($this->file_path)),
            'expires_at' => $this->expires_at,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at,
            // Withdrawn by operations after approval (status is rejected).
            'revoked_at' => $this->revoked_at,
            'created_at' => $this->created_at,
        ];
    }
}
