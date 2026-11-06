<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed', 'different:current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.different' => 'The new password must be different from your current password.',
        ];
    }
}
