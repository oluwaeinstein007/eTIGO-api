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
     * Multipliers run 1.00x–3.00x in 0.05x steps, so the agreed 1.25x off-peak
     * bonus fits and a typo can't hand out runaway points (points drive tier discounts).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'multipliers' => ['required', 'array', 'min:1'],
            'multipliers.*.condition_type' => ['required', 'string', Rule::in(array_column(MultiplierConditionType::cases(), 'value'))],
            'multipliers.*.multiplier_value' => ['required', 'numeric', 'min:1.00', 'max:3.00', 'multiple_of:0.05'],
            'multipliers.*.is_stackable' => ['required', 'boolean'],
        ];
    }
}
