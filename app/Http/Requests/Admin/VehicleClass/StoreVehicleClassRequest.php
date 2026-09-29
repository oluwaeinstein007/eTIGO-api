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
            'icon_url' => ['nullable', 'string', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
