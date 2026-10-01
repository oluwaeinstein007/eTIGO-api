<?php

namespace App\Http\Requests\Admin\Surge;

use App\Enums\SurgeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSurgeRuleRequest extends FormRequest
{
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
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'vehicle_class_id' => ['nullable', 'integer', 'exists:vehicle_classes,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(SurgeType::class)],
            'multiplier' => ['required', 'numeric', 'min:1.00', 'max:5.00'],
            'conditions' => ['sometimes', 'array'],
            'conditions.days_of_week' => ['required_if:type,time_based', 'array'],
            'conditions.days_of_week.*' => ['integer', 'min:1', 'max:7'],
            'conditions.start_time' => ['required_if:type,time_based', 'date_format:H:i'],
            'conditions.end_time' => ['required_if:type,time_based', 'date_format:H:i'],
            'conditions.min_demand_supply_ratio' => ['required_if:type,demand_based', 'numeric', 'min:1.0'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ];
    }
}
