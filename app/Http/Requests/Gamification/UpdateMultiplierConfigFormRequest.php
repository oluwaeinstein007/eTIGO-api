<?php

namespace App\Http\Requests\Gamification;

use App\Enums\MultiplierConditionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMultiplierConfigFormRequest extends FormRequest
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
            'multipliers' => ['required', 'array', 'min:1'],
            'multipliers.*.condition_type' => ['required', 'string', Rule::in(array_column(MultiplierConditionType::cases(), 'value'))],
            'multipliers.*.multiplier_value' => ['required', 'numeric', 'min:1.00', 'max:10.00'],
            'multipliers.*.is_stackable' => ['required', 'boolean'],
        ];
    }
}
