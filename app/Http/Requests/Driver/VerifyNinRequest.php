<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class VerifyNinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nin_number' => ['required', 'string', 'digits:11'],
        ];
    }

    public function messages(): array
    {
        return [
            'nin_number.digits' => 'NIN must be exactly 11 digits.',
        ];
    }
}
