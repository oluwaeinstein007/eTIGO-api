<?php

namespace App\Http\Requests\Driver;

use App\Enums\CityLaunchStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'city_id' => [
                'sometimes', 'required', 'uuid',
                Rule::exists('cities', 'id')->whereIn('launch_status', [CityLaunchStatus::Onboarding->value, CityLaunchStatus::Live->value]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'city_id.exists' => 'The selected city is not open for driver sign-ups.',
        ];
    }
}
