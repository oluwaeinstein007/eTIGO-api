<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SocialAuthRequest extends FormRequest
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
            'provider' => ['required', 'string', 'in:google,apple,facebook'],
            'token' => ['required', 'string'],
            'type' => ['required', 'string', 'in:passenger,driver'],
        ];
    }
}
