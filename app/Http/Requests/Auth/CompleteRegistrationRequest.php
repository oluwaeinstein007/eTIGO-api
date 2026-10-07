<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteRegistrationRequest extends FormRequest
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
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => [
                'nullable', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->where('type', $this->input('type')),
            ],
            'type' => ['required', 'string', 'in:passenger,driver'],
            'city_id' => ['required_if:type,driver', 'nullable', 'uuid', 'exists:cities,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already registered as a '.$this->input('type').'.',
        ];
    }
}
