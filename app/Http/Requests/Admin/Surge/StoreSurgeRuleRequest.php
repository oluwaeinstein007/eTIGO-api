<?php

namespace App\Http\Requests\Admin\Surge;

use App\Enums\SurgeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSurgeRuleRequest extends FormRequest
{
    use ValidatesSurgeConditions;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'city_id' => ['required', 'uuid', 'exists:cities,id'],
            'vehicle_class_id' => ['nullable', 'uuid', 'exists:vehicle_classes,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(SurgeType::class)],
            'multiplier' => ['required', 'numeric', 'min:1.00', 'max:5.00'],
            ...$this->conditionRules(
                timeBased: $this->input('type') === SurgeType::TimeBased->value,
                demandBased: $this->input('type') === SurgeType::DemandBased->value,
            ),
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ];
    }
}
