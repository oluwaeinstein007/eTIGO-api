<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'match_data' => $this->whenLoaded('driver', function () {
                $data = $this->match_data ?? [];
                unset($data['sdk_token']);

                return $data;
            }, $this->safeMatchData()),
            'failure_reason' => $this->failure_reason,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    private function safeMatchData(): array
    {
        $data = $this->match_data ?? [];
        unset($data['sdk_token']);

        return $data;
    }
}
