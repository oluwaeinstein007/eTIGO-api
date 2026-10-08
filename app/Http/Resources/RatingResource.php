<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ride_id' => $this->ride_id,
            'score' => $this->score,
            'comment' => $this->comment,
            'rated_by' => new UserResource($this->whenLoaded('ratedBy')),
            'rated_user' => new UserResource($this->whenLoaded('ratedUser')),
            'created_at' => $this->created_at,
        ];
    }
}
