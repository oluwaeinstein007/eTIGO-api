<?php

namespace App\Http\Requests\Admin;

use App\Enums\VehicleOwnershipType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CompleteDriverOnboardingRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:500'],
            'nin' => ['nullable', 'string', 'max:20'],
            'vehicle_ownership_type' => ['nullable', new Enum(VehicleOwnershipType::class)],
        ];
    }
}
