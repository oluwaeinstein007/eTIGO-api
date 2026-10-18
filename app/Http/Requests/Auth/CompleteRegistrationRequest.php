<?php

namespace App\Http\Requests\Auth;

use App\Enums\CityLaunchStatus;
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
            // Drivers sign up in onboarding and live cities, not paused ones.
            // Only a driver's city is stored; passengers' is not checked.
            'city_id' => [
                'required_if:type,driver', 'nullable', 'uuid',
                $this->input('type') === 'driver'
                    ? Rule::exists('cities', 'id')->whereIn('launch_status', [CityLaunchStatus::Onboarding->value, CityLaunchStatus::Live->value])
                    : Rule::exists('cities', 'id'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already registered as a '.$this->input('type').'.',
            'city_id.exists' => 'The selected city is not open for driver sign-ups.',
        ];
    }
}
