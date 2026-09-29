<?php

namespace App\Http\Requests\Auth;

use App\Enums\SocialProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SocialLoginRequest extends FormRequest
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
            'provider' => ['required', 'string', new Enum(SocialProvider::class)],
            'access_token' => ['required', 'string'],
            'type' => ['required', 'string', 'in:passenger,driver'],
        ];
    }
}
