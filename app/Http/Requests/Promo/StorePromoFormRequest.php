<?php

namespace App\Http\Requests\Promo;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePromoFormRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', 'unique:promo_codes,code'],
            'description' => ['nullable', 'string', 'max:500'],
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'discount_value' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'max_discount_cap' => ['nullable', 'numeric', 'min:0.01', 'max:99999.99'],
            'total_redemption_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['integer', 'min:1', 'max:100'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:starts_at'],
            'geo_fence' => ['nullable', 'array'],
            'geo_fence.center_lat' => ['required_with:geo_fence.radius_km', 'numeric', 'between:-90,90'],
            'geo_fence.center_lng' => ['required_with:geo_fence.radius_km', 'numeric', 'between:-180,180'],
            'geo_fence.radius_km' => ['required_with:geo_fence.center_lat', 'numeric', 'min:0.1', 'max:500'],
            'geo_fence.polygon' => ['nullable', 'array', 'min:3'],
            'geo_fence.polygon.*.lat' => ['required_with:geo_fence.polygon', 'numeric', 'between:-90,90'],
            'geo_fence.polygon.*.lng' => ['required_with:geo_fence.polygon', 'numeric', 'between:-180,180'],
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
                if (($data['peak_only'] ?? false) && ($data['off_peak_only'] ?? false)) {
                    $validator->errors()->add('peak_only', 'A promo cannot be both peak-only and off-peak-only.');
                }

                if (($data['discount_type'] ?? null) === 'percentage' && ($data['discount_value'] ?? 0) > 100) {
                    $validator->errors()->add('discount_value', 'Percentage discount cannot exceed 100%.');
                }

                if (($data['min_order_count'] ?? null) !== null
                    && ($data['max_order_count'] ?? null) !== null
                    && $data['min_order_count'] > $data['max_order_count']) {
                    $validator->errors()->add('min_order_count', 'Minimum order count cannot exceed maximum order count.');
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
