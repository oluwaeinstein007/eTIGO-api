<?php

namespace App\Http\Requests\Admin\City;

use App\Support\NigerianStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCityRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', 'unique:cities,name'],
            'state' => ['required', 'string', Rule::in(NigerianStates::names())],
            'boundary' => ['nullable', 'array'],
            'boundary.type' => ['required_with:boundary', 'string', 'in:Point,Polygon'],
            'boundary.coordinates' => ['required_with:boundary', 'array'],
            'boundary.radius_km' => ['nullable', 'numeric', 'min:0'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'vehicle_class_ids' => ['nullable', 'array'],
            'vehicle_class_ids.*' => ['uuid', 'distinct', 'exists:vehicle_classes,id'],
        ];
    }
}
