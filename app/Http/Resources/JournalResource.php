<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'entries' => LedgerEntryResource::collection($this->whenLoaded('entries')),
        ];
    }
}
