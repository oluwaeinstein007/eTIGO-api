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
            'boundary' => ['nullable', 'array'],
            'boundary.type' => ['required_with:boundary', 'string', 'in:Point,Polygon'],
            'boundary.coordinates' => ['required_with:boundary', 'array'],
            'boundary.radius_km' => ['nullable', 'numeric', 'min:0'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
