<?php

namespace App\Http\Requests\Admin\Fleet;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFleetVehicleRequest extends FormRequest
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
            'make' => ['sometimes', 'required', 'string', 'max:100'],
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'colour' => ['sometimes', 'required', 'string', 'max:50'],
            'plate_number' => ['sometimes', 'required', 'string', 'max:20', 'unique:vehicles,plate_number,'.$this->route('vehicle')->id],
            'year' => ['sometimes', 'required', 'integer', 'min:2000', 'max:'.(date('Y') + 1)],
            'vehicle_class_id' => ['sometimes', 'required', 'uuid', 'exists:vehicle_classes,id'],
        ];
    }
}
