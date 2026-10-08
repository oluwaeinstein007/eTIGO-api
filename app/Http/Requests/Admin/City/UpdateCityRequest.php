<?php

namespace App\Http\Requests\Admin\City;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCityRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('cities', 'name')->ignore($this->route('city'))],
            'state' => ['sometimes', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'boundary' => ['nullable', 'array'],
            'boundary.type' => ['required_with:boundary', 'string', 'in:Point,Polygon'],
            'boundary.coordinates' => ['required_with:boundary', 'array'],
            'boundary.radius_km' => ['nullable', 'numeric', 'min:0'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'vehicle_class_ids' => ['nullable', 'array'],
            'vehicle_class_ids.*' => ['uuid', 'distinct', 'exists:vehicle_classes,id'],
        ];
    }
}
