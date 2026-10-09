<?php

namespace App\Http\Resources;

use App\Enums\MultiplierConditionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PointMultiplierConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $conditionType = $this->condition_type instanceof MultiplierConditionType
            ? $this->condition_type
            : MultiplierConditionType::tryFrom($this->condition_type);

        return [
            'id' => $this->id,
            'condition_type' => $conditionType?->value ?? $this->condition_type,
            'condition_label' => $conditionType?->label(),
            'multiplier_value' => (float) $this->multiplier_value,
            'is_stackable' => $this->is_stackable,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
