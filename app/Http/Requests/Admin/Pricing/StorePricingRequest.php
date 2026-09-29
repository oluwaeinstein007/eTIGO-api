<?php

namespace App\Http\Requests\Admin\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class StorePricingRequest extends FormRequest
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
            'vehicle_class_id' => ['required', 'integer', 'exists:vehicle_classes,id'],
            'base_fare' => ['required', 'numeric', 'min:0'],
            'per_km_rate' => ['required', 'numeric', 'min:0'],
            'per_minute_rate' => ['required', 'numeric', 'min:0'],
            'minimum_fare' => ['required', 'numeric', 'min:0'],
            'waiting_time_rate' => ['nullable', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date', 'after:now'],
        ];
    }
}
