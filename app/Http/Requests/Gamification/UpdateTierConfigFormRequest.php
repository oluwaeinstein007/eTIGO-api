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
     * booking_fee_discount_pct is the percent off the base fare; 0 means the
     * tier has no discount. exclusive_promotions_enabled is optional, so a
     * dashboard that doesn't send it keeps the stored value.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tiers' => ['required', 'array', 'size:5'],
            'tiers.*.tier_level' => ['required', 'integer', 'between:1,5', 'distinct'],
            'tiers.*.tier_name' => ['required', 'string', 'max:50'],
            'tiers.*.min_points_required' => ['required', 'integer', 'min:0'],
            'tiers.*.booking_fee_discount_pct' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'tiers.*.ev_reservation_fee_waived' => ['required', 'boolean'],
            'tiers.*.priority_matching_enabled' => ['required', 'boolean'],
            'tiers.*.exclusive_promotions_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
