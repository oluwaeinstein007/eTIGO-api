<?php

namespace App\Http\Requests\Gamification;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTierConfigFormRequest extends FormRequest
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
            'tiers' => ['required', 'array', 'size:5'],
            'tiers.*.tier_level' => ['required', 'integer', 'between:1,5', 'distinct'],
            'tiers.*.tier_name' => ['required', 'string', 'max:50'],
            'tiers.*.min_points_required' => ['required', 'integer', 'min:0'],
            'tiers.*.booking_fee_discount_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'tiers.*.ev_reservation_fee_waived' => ['required', 'boolean'],
            'tiers.*.priority_matching_enabled' => ['required', 'boolean'],
        ];
    }
}
