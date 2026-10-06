<?php

namespace App\Http\Requests\Passenger;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('users')->ignore($this->user())],
            'profile_photo' => ['sometimes', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }
}
