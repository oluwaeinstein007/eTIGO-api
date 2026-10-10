<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'type' => $this->type,
            'admin_role' => $this->when($this->isAdmin(), $this->admin_role),
            'phone_verified_at' => $this->phone_verified_at,
            'is_active' => $this->is_active,
            'profile_photo_url' => $this->profile_photo_path
                ? Storage::disk(config('filesystems.uploads'))->url($this->profile_photo_path)
                : null,
            'rating' => $this->when($this->isDriver(), fn () => $this->averageRating()),
            'vehicle' => $this->when(
                $this->isDriver() && $this->relationLoaded('driver') && $this->driver?->relationLoaded('vehicle'),
                fn () => $this->driver?->vehicle ? new VehicleResource($this->driver->vehicle) : null,
            ),
            'created_at' => $this->created_at,
        ];
    }
}
