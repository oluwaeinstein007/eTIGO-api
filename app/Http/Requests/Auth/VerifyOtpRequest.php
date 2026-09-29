<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
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
        $isNewUser = ! User::where('phone', $this->input('phone'))
            ->where('type', $this->input('type'))
            ->exists();

        $nameRules = $isNewUser
            ? ['required', 'string', 'max:100']
            : ['sometimes', 'string', 'max:100'];

        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'code' => ['required', 'string', 'size:6'],
            'type' => ['required', 'string', 'in:passenger,driver'],
            'first_name' => $nameRules,
            'last_name' => $nameRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Phone number must be in international format (e.g. +2341234567890).',
            'code.size' => 'Verification code must be exactly 6 digits.',
        ];
    }
}
