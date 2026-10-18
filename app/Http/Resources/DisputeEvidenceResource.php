<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesUploadUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisputeEvidenceResource extends JsonResource
{
    use ResolvesUploadUrls;

    public function toArray(Request $request): array
    {
        $isAdmin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'url' => $this->resolveUploadUrl($this->file_path),
            'file_type' => $this->file_type,
            'file_size_bytes' => $this->file_size_bytes,
            'original_name' => $this->original_name,
            'caption' => $this->caption,
            'uploaded_by_user_id' => $this->when($isAdmin, $this->uploaded_by_user_id),
            'uploaded_at' => $this->uploaded_at,
        ];
    }
}
