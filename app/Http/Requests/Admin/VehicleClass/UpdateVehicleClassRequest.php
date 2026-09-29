<?php

namespace App\Http\Requests\Admin\VehicleClass;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleClassRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('vehicle_classes', 'name')->ignore($this->route('vehicleClass'))],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'icon_url' => ['nullable', 'string', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
