<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDriverProfileRequest extends FormRequest
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
            'licence_number' => ['sometimes', 'required', 'string', 'max:50'],
            'city_id' => ['sometimes', 'required', 'integer', 'exists:cities,id'],
        ];
    }
}
