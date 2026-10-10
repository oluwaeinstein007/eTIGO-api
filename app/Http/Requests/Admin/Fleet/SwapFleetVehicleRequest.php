<?php

namespace App\Http\Requests\Admin\Fleet;

use Illuminate\Foundation\Http\FormRequest;

class SwapFleetVehicleRequest extends FormRequest
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
            'new_vehicle_id' => ['required', 'uuid', 'exists:vehicles,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'carry_over_remitted' => ['required', 'boolean'],
            'daily_remittance_target' => ['nullable', 'numeric', 'min:1000'],
            'total_vehicle_cost' => ['nullable', 'numeric', 'min:100000'],
        ];
    }
}
