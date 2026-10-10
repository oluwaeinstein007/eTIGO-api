<?php

namespace App\Http\Requests\Promo;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePromoFormRequest extends FormRequest
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
        $promoId = $this->route('promo')?->id;

        return [
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('promo_codes', 'code')->ignore($promoId)],
            'description' => ['nullable', 'string', 'max:500'],
            'discount_type' => ['sometimes', Rule::enum(DiscountType::class)],
            'discount_value' => ['sometimes', 'numeric', 'min:0.01', 'max:99999.99'],
            'max_discount_cap' => ['nullable', 'numeric', 'min:0.01', 'max:99999.99'],
            'total_redemption_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['sometimes', 'date'],
            'expires_at' => ['sometimes', 'date', 'after:starts_at'],
            'geo_fence' => ['nullable', 'array'],
            'min_order_count' => ['nullable', 'integer', 'min:0'],
            'max_order_count' => ['nullable', 'integer', 'min:0'],
            'min_tier_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'peak_only' => ['boolean'],
            'off_peak_only' => ['boolean'],
            'city_id' => ['nullable', 'uuid', 'exists:cities,id'],
            'vehicle_class_id' => ['nullable', 'uuid', 'exists:vehicle_classes,id'],
            'minimum_fare_amount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return void
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $data = $validator->getData();
                $promo = $this->route('promo');

                $peakOnly = $data['peak_only'] ?? $promo?->peak_only ?? false;
                $offPeakOnly = $data['off_peak_only'] ?? $promo?->off_peak_only ?? false;

                if ($peakOnly && $offPeakOnly) {
                    $validator->errors()->add('peak_only', 'A promo cannot be both peak-only and off-peak-only.');
                }

                $discountType = $data['discount_type'] ?? $promo?->discount_type?->value;
                $discountValue = $data['discount_value'] ?? $promo?->discount_value ?? 0;

                if ($discountType === 'percentage' && $discountValue > 100) {
                    $validator->errors()->add('discount_value', 'Percentage discount cannot exceed 100%.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
