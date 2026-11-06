<?php

namespace App\Http\Requests\Admin;

use Closure;
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
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password',
                // bcrypt ignores everything past 72 bytes, so longer passwords would not be what they seem.
                fn (string $attribute, mixed $value, Closure $fail) => is_string($value) && strlen($value) > 72
                    ? $fail('The new password may not be longer than 72 bytes.')
                    : null,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'password.different' => 'The new password must be different from your current password.',
        ];
    }
}
