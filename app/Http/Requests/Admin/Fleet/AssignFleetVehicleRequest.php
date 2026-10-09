<?php

namespace App\Http\Requests\Admin\Fleet;

use Illuminate\Foundation\Http\FormRequest;

class AssignFleetVehicleRequest extends FormRequest
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
            'driver_id' => ['required', 'uuid', 'exists:drivers,id'],
            'daily_remittance_target' => ['required', 'numeric', 'min:1000'],
            'total_vehicle_cost' => ['required', 'numeric', 'min:100000'],
            'agreement_start_date' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
