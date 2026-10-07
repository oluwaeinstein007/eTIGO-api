<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
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
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'colour' => ['required', 'string', 'max:50'],
            'plate_number' => ['required', 'string', 'max:20', 'unique:vehicles,plate_number'],
            'year' => ['required', 'integer', 'min:2000', 'max:'.(date('Y') + 1)],
            'vehicle_class_id' => ['required', 'uuid', 'exists:vehicle_classes,id'],
        ];
    }
}
