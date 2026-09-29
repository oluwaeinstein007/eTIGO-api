<?php

namespace App\Http\Requests\Admin\City;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCityVehicleClassesRequest extends FormRequest
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
            'vehicle_classes' => ['required', 'array', 'min:1'],
            'vehicle_classes.*.vehicle_class_id' => ['required', 'integer', 'distinct', 'exists:vehicle_classes,id'],
            'vehicle_classes.*.is_active' => ['required', 'boolean'],
        ];
    }
}
