<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
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
        $vehicle = $this->user()->driver?->vehicle;

        return [
            'make' => ['sometimes', 'required', 'string', 'max:100'],
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'colour' => ['sometimes', 'required', 'string', 'max:50'],
            'plate_number' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('vehicles', 'plate_number')->ignore($vehicle)],
            'year' => ['nullable', 'integer', 'min:2000', 'max:'.(date('Y') + 1)],
        ];
    }
}
