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
            // Percent off the base fare; 0 means the tier has no discount.
            'tiers.*.booking_fee_discount_pct' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'tiers.*.ev_reservation_fee_waived' => ['required', 'boolean'],
            'tiers.*.priority_matching_enabled' => ['required', 'boolean'],
            // Optional so older dashboards that don't send it keep the stored value.
            'tiers.*.exclusive_promotions_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
