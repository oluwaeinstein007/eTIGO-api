<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'type' => $this->type,
            'profile_photo_url' => $this->profile_photo_path
                ? Storage::disk(config('filesystems.uploads'))->url($this->profile_photo_path)
                : null,
        ];
    }
}
