<?php

namespace App\Http\Requests\Admin\VehicleClass;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleClassRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', 'unique:vehicle_classes,name'],
            'display_name' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1', 'max:20'],
            'icon' => ['nullable', 'string', 'max:50', 'in:lite,comfort,xl'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'city_ids' => ['sometimes', 'array'],
            'city_ids.*' => ['uuid', 'exists:cities,id'],
        ];
    }
}
